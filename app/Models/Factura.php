<?php

namespace App\Models;

use App\Enums\EstadoCancelacion;
use App\Enums\EstadoComplementoPago;
use App\Enums\EstadoFactura;
use App\Enums\FormaPago;
use App\Enums\MetodoPago;
use App\Enums\MotivoCancelacion;
use App\Enums\TipoDescuento;
use App\Enums\TipoErrorTimbrado;
use App\Enums\UsoCfdi;
use Carbon\CarbonImmutable;
use Database\Factories\FacturaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Solo la cabecera que captura el usuario es asignable. El folio lo pone
 * FacturaController al crear; el estado, los sellos, las copias fiscales y el
 * error solo los escriben los métodos de timbrado y cancelación; los totales,
 * aplicarTotales() con la calculadora. cotizacion_id y duplicada_de_id (el
 * origen) los pone FacturaController::store y no cambian después.
 */
#[Fillable([
    'cliente_id',
    'uso_cfdi',
    'forma_pago',
    'metodo_pago',
    'descuento_global_tipo',
    'descuento_global_valor',
])]
class Factura extends Model
{
    /** @use HasFactory<FacturaFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'estado' => 'borrador',
    ];

    public const MONEDA = 'MXN';

    /**
     * Tipo de comprobante: siempre Ingreso.
     */
    public const TIPO_COMPROBANTE = 'I';

    /**
     * Columnas que se pueden filtrar desde el listado.
     */
    public const FILTROS = ['cliente', 'rfc', 'folio', 'uuid', 'estado'];

    /**
     * Columnas de totales que escribe la calculadora.
     */
    public const TOTALES = ['subtotal', 'total_descuento', 'base_iva_16', 'total_iva_16', 'base_iva_0', 'base_exento', 'total'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Incluye los clientes eliminados, para que la factura siga mostrando el
     * nombre de su cliente.
     *
     * @return BelongsTo<Cliente, $this>
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class)->withTrashed();
    }

    /**
     * @return HasMany<FacturaLinea, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(FacturaLinea::class)->orderBy('orden');
    }

    /**
     * @return HasOne<ComplementoPago, $this>
     */
    public function complementoPago(): HasOne
    {
        return $this->hasOne(ComplementoPago::class);
    }

    /**
     * La cotización de la que salió, si salió de una.
     *
     * @return BelongsTo<Cotizacion, $this>
     */
    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    /**
     * @return BelongsTo<Factura, $this>
     */
    public function duplicadaDe(): BelongsTo
    {
        return $this->belongsTo(Factura::class, 'duplicada_de_id');
    }

    /**
     * @return BelongsTo<Factura, $this>
     */
    public function sustituta(): BelongsTo
    {
        return $this->belongsTo(Factura::class, 'factura_sustituta_id');
    }

    /**
     * Escribe los totales calculados (no guarda).
     *
     * @param  array<string, mixed>  $totales  resultado de CalculadoraTotalesDocumento::calcular()
     */
    public function aplicarTotales(array $totales): void
    {
        foreach (self::TOTALES as $columna) {
            $this->{$columna} = $totales[$columna];
        }
    }

    /**
     * Corregir los datos solo tiene sentido si facturapi.io los rechazó; tras
     * una falla del PAC se reintenta con los mismos.
     */
    public function esEditable(): bool
    {
        return $this->estado === EstadoFactura::Borrador
            || ($this->estado === EstadoFactura::Pendiente && $this->tipo_error_timbrado === TipoErrorTimbrado::Datos);
    }

    /**
     * Antes de timbrar no hay nada fiscal que preservar.
     */
    public function puedeEliminarse(): bool
    {
        return $this->estado === EstadoFactura::Borrador || $this->estado === EstadoFactura::Pendiente;
    }

    public function puedeReintentarse(): bool
    {
        return $this->estado === EstadoFactura::Pendiente;
    }

    /**
     * Hay XML, PDF y se puede compartir.
     */
    public function tieneDocumentoFiscal(): bool
    {
        return $this->estado === EstadoFactura::Timbrada || $this->estado === EstadoFactura::Cancelada;
    }

    public function puedeEnviarse(): bool
    {
        return $this->estado === EstadoFactura::Timbrada;
    }

    public function cancelacionEnCurso(): bool
    {
        return $this->estado_cancelacion?->enCurso() ?? false;
    }

    public function puedeCancelarse(): bool
    {
        return $this->estado === EstadoFactura::Timbrada
            && in_array($this->estado_cancelacion, [null, EstadoCancelacion::Ninguna, EstadoCancelacion::Rechazada], true);
    }

    public function puedeRegistrarComplemento(): bool
    {
        return $this->estado === EstadoFactura::Timbrada
            && $this->metodo_pago === MetodoPago::Diferido
            && $this->complementoPago?->estado !== EstadoComplementoPago::Timbrado;
    }

    /**
     * Datos fiscales del receptor: la copia tomada al timbrar o, antes de
     * timbrar, los vivos del cliente. El PDF, el correo y el detalle leen de
     * aquí para coincidir siempre con el XML.
     *
     * @return array{rfc: string, razon_social: string, regimen_fiscal: string, codigo_postal: string, correo: string|null}
     */
    public function receptor(): array
    {
        if ($this->receptor_rfc !== null) {
            return [
                'rfc' => $this->receptor_rfc,
                'razon_social' => $this->receptor_razon_social,
                'regimen_fiscal' => $this->receptor_regimen_fiscal,
                'codigo_postal' => $this->receptor_codigo_postal,
                'correo' => $this->receptor_correo,
            ];
        }

        return self::datosReceptor($this->cliente);
    }

    /**
     * @return array{rfc: string, razon_social: string, regimen_fiscal: string, codigo_postal: string, correo: string|null}
     */
    public static function datosReceptor(Cliente $cliente): array
    {
        return [
            'rfc' => $cliente->rfc,
            'razon_social' => $cliente->razon_social,
            'regimen_fiscal' => $cliente->regimen_fiscal->value,
            'codigo_postal' => $cliente->codigo_postal_fiscal,
            'correo' => $cliente->correo,
        ];
    }

    /**
     * Emisor copiado al timbrar (null antes).
     *
     * @return array{rfc: string, razon_social: string, regimen_fiscal: string, codigo_postal: string}|null
     */
    public function emisor(): ?array
    {
        if ($this->emisor_rfc === null) {
            return null;
        }

        return [
            'rfc' => $this->emisor_rfc,
            'razon_social' => $this->emisor_razon_social,
            'regimen_fiscal' => $this->emisor_regimen_fiscal,
            'codigo_postal' => $this->lugar_expedicion,
        ];
    }

    /**
     * Serie y folio fiscal asignados por facturapi.io ("A123"); null antes de
     * timbrar.
     */
    public function folioFiscal(): ?string
    {
        if ($this->facturapi_folio === null) {
            return null;
        }

        return ($this->facturapi_serie ?? '').$this->facturapi_folio;
    }

    /**
     * El folio que identifica al documento: el fiscal si ya se timbró, el
     * interno si no.
     */
    public function folioVisible(): string
    {
        return $this->folioFiscal() ?? $this->folio_formateado;
    }

    /**
     * Identificador propio ante facturapi.io (external_id e idempotency_key):
     * fijo en todos los reintentos de esta factura. La fecha de creación evita
     * que choque con otra factura que reciba el mismo id tras reiniciar la base.
     */
    public function referenciaExterna(): string
    {
        return 'factura-'.$this->id.'-'.$this->created_at->getTimestamp();
    }

    public function nombreArchivo(string $extension): string
    {
        return 'factura-'.$this->folioVisible().'.'.$extension;
    }

    /**
     * Guarda el timbrado exitoso: sellos (nombres verificados contra la API
     * real de facturapi.io), copias fiscales y estado.
     *
     * @param  array<string, mixed>  $respuesta
     * @param  array{rfc: string, razon_social: string, regimen_fiscal: string, codigo_postal: string, correo: string|null}  $receptor
     * @param  array{rfc: string, razon_social: string, regimen_fiscal: string, codigo_postal: string}  $emisor
     */
    public function aplicarRespuestaTimbrado(array $respuesta, array $receptor, array $emisor): void
    {
        $this->forceFill([
            'estado' => EstadoFactura::Timbrada,
            'facturapi_invoice_id' => $respuesta['id'] ?? null,
            'uuid_fiscal' => $respuesta['uuid'] ?? null,
            'facturapi_serie' => $respuesta['series'] ?? null,
            'facturapi_folio' => $respuesta['folio_number'] ?? null,
            'sello_cfdi' => data_get($respuesta, 'stamp.signature'),
            'sello_sat' => data_get($respuesta, 'stamp.sat_signature'),
            'no_certificado_sat' => data_get($respuesta, 'stamp.sat_cert_number'),
            'fecha_timbrado' => self::fecha(data_get($respuesta, 'stamp.date')),
            'cadena_original_sat' => data_get($respuesta, 'stamp.complement_string'),
            'version_comprobante' => self::versionCfdi($respuesta['cfdi_version'] ?? null),
            'url_verificacion_sat' => $respuesta['verification_url'] ?? null,
            'receptor_rfc' => $receptor['rfc'],
            'receptor_razon_social' => $receptor['razon_social'],
            'receptor_regimen_fiscal' => $receptor['regimen_fiscal'],
            'receptor_codigo_postal' => $receptor['codigo_postal'],
            'receptor_correo' => $receptor['correo'],
            'emisor_rfc' => $emisor['rfc'],
            'emisor_razon_social' => $emisor['razon_social'],
            'emisor_regimen_fiscal' => $emisor['regimen_fiscal'],
            'lugar_expedicion' => $emisor['codigo_postal'],
            'error_timbrado' => null,
            'tipo_error_timbrado' => null,
        ])->save();
    }

    public function registrarErrorTimbrado(string $mensaje, TipoErrorTimbrado $tipo): void
    {
        $this->forceFill([
            'estado' => EstadoFactura::Pendiente,
            'error_timbrado' => $mensaje,
            'tipo_error_timbrado' => $tipo,
        ])->save();
    }

    /**
     * Refleja el cancellation_status de facturapi.io; solo accepted pasa la
     * factura a cancelada.
     */
    public function aplicarEstadoCancelacion(EstadoCancelacion $estado): void
    {
        $this->estado_cancelacion = $estado;

        if ($estado === EstadoCancelacion::Aceptada && $this->estado !== EstadoFactura::Cancelada) {
            $this->estado = EstadoFactura::Cancelada;
            $this->fecha_cancelacion = now();
        }

        $this->save();
    }

    /**
     * stamp.date llega como la FechaTimbrado del XML: hora de México sin zona
     * ("2026-09-28T18:50:00"), verificado contra el sandbox. Si trae zona, se
     * respeta. Se guarda en UTC.
     */
    public static function fecha(mixed $valor): ?CarbonImmutable
    {
        if (! is_string($valor) || $valor === '') {
            return null;
        }

        return CarbonImmutable::parse($valor, config('app.zona_negocio'))->utc();
    }

    /**
     * cfdi_version llega como número (4 o 4.0): se guarda "4.0".
     */
    public static function versionCfdi(mixed $valor): ?string
    {
        return is_numeric($valor) ? number_format((float) $valor, 1, '.', '') : null;
    }

    /**
     * "FAC-0012".
     *
     * @return Attribute<string, never>
     */
    protected function folioFormateado(): Attribute
    {
        return Attribute::get(fn (): string => 'FAC-'.str_pad((string) $this->folio, 4, '0', STR_PAD_LEFT));
    }

    /**
     * Aplica los filtros del listado (combinados con Y). Los vacíos se ignoran.
     *
     * @param  Builder<self>  $consulta
     * @param  array{cliente?: string, rfc?: string, folio?: array{interno: int|null, serie: string|null, fiscal: int|null}|null, uuid?: string, estado?: string}  $filtros
     */
    #[Scope]
    protected function filtrar(Builder $consulta, array $filtros): void
    {
        $cliente = trim($filtros['cliente'] ?? '');
        $rfc = trim($filtros['rfc'] ?? '');
        $uuid = trim($filtros['uuid'] ?? '');
        $folio = $filtros['folio'] ?? null;

        if ($cliente !== '') {
            $consulta->whereHas('cliente', fn (Builder $clientes) => $clientes->withTrashed()->where(
                fn (Builder $nombres) => $nombres->where('razon_social', 'like', "%{$cliente}%")->orWhere('nombre_comercial', 'like', "%{$cliente}%")
            ));
        }

        if ($rfc !== '') {
            $consulta->whereHas('cliente', fn (Builder $clientes) => $clientes->withTrashed()->where('rfc', 'like', "%{$rfc}%"));
        }

        if ($folio !== null) {
            $consulta->where(function (Builder $folios) use ($folio) {
                if ($folio['interno'] !== null) {
                    $folios->orWhere('folio', $folio['interno']);
                }

                if ($folio['fiscal'] !== null) {
                    $folios->orWhere(fn (Builder $fiscal) => $fiscal
                        ->where('facturapi_folio', $folio['fiscal'])
                        ->when($folio['serie'] !== null, fn (Builder $conSerie) => $conSerie->where('facturapi_serie', $folio['serie'])));
                }
            });
        }

        if ($uuid !== '') {
            $consulta->where('uuid_fiscal', 'like', "%{$uuid}%");
        }

        if (($filtros['estado'] ?? '') !== '') {
            $consulta->where('estado', $filtros['estado']);
        }
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado' => EstadoFactura::class,
            'uso_cfdi' => UsoCfdi::class,
            'forma_pago' => FormaPago::class,
            'metodo_pago' => MetodoPago::class,
            'descuento_global_tipo' => TipoDescuento::class,
            'descuento_global_valor' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total_descuento' => 'decimal:2',
            'base_iva_16' => 'decimal:2',
            'total_iva_16' => 'decimal:2',
            'base_iva_0' => 'decimal:2',
            'base_exento' => 'decimal:2',
            'total' => 'decimal:2',
            'facturapi_folio' => 'integer',
            'fecha_timbrado' => 'immutable_datetime',
            'tipo_error_timbrado' => TipoErrorTimbrado::class,
            'motivo_cancelacion' => MotivoCancelacion::class,
            'estado_cancelacion' => EstadoCancelacion::class,
            'fecha_cancelacion' => 'immutable_datetime',
        ];
    }
}
