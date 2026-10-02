<?php

namespace App\Models;

use App\Enums\EstadoCotizacion;
use App\Enums\EstadoFactura;
use App\Enums\TipoDescuento;
use App\Enums\TipoPago;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use Carbon\CarbonImmutable;
use Database\Factories\CotizacionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * folio y estado no son asignables: el folio lo pone CotizacionController al
 * crear (con el contador del usuario) y el estado solo cambia por las
 * acciones del ciclo (enviar, pagar, entregar, editar). duplicada_de_id lo
 * pone solo CotizacionController::duplicar.
 *
 * "Facturada" no es un estado: es tener una factura vigente (no cancelada)
 * vinculada por facturas.cotizacion_id.
 *
 * Los totales los escribe solo aplicarTotales(), con la calculadora.
 */
#[Fillable([
    'cliente_id',
    'descuento_global_tipo',
    'descuento_global_valor',
])]
class Cotizacion extends Model
{
    /** @use HasFactory<CotizacionFactory> */
    use HasFactory;

    /**
     * Str::plural no conoce el español: inferiría "cotizacions".
     */
    protected $table = 'cotizaciones';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'estado' => 'borrador',
    ];

    /**
     * Días sin movimiento tras los que una cotización editable y sin pagos se
     * elimina sola. Único lugar donde se define.
     */
    public const DIAS_CADUCIDAD = 30;

    /**
     * Días antes del borrado automático en que se empieza a avisar.
     */
    public const DIAS_AVISO_CADUCIDAD = 7;

    /**
     * Valor del filtro de estado que pide las que ya muestran el aviso de
     * caducidad (scope porCaducar).
     */
    public const POR_CADUCAR = 'por_caducar';

    /**
     * Valores del filtro de estado que piden las que tienen factura vigente y
     * las que se pueden facturar y todavía no la tienen.
     */
    public const FACTURADAS = 'facturadas';

    public const POR_FACTURAR = 'por_facturar';

    /**
     * Estados en los que una cotización se puede facturar.
     */
    public const ESTADOS_FACTURABLES = [EstadoCotizacion::Enviada, EstadoCotizacion::Pagada, EstadoCotizacion::ProductoEntregado];

    /**
     * Columnas que se pueden filtrar desde el listado (además de las fechas).
     */
    public const FILTROS = ['cliente', 'rfc', 'folio', 'estado'];

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
     * Incluye los clientes eliminados, para que la cotización siga mostrando
     * el nombre de su cliente.
     *
     * @return BelongsTo<Cliente, $this>
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class)->withTrashed();
    }

    /**
     * @return HasMany<CotizacionLinea, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(CotizacionLinea::class)->orderBy('orden');
    }

    /**
     * @return HasMany<CotizacionPago, $this>
     */
    public function pagos(): HasMany
    {
        return $this->hasMany(CotizacionPago::class)->orderBy('fecha_pago')->orderBy('id');
    }

    /**
     * @return HasMany<Factura, $this>
     */
    public function facturas(): HasMany
    {
        return $this->hasMany(Factura::class);
    }

    /**
     * La factura que la marca como facturada: cualquiera que no esté
     * cancelada, aunque todavía no se haya timbrado.
     *
     * @return HasOne<Factura, $this>
     */
    public function facturaVigente(): HasOne
    {
        return $this->hasOne(Factura::class)->where('estado', '!=', EstadoFactura::Cancelada->value);
    }

    /**
     * @return BelongsTo<Cotizacion, $this>
     */
    public function duplicadaDe(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class, 'duplicada_de_id');
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
     * Usa pagos_sum_monto cuando el listado lo precargó con withSum().
     */
    public function totalPagado(): string
    {
        $suma = array_key_exists('pagos_sum_monto', $this->attributes)
            ? $this->attributes['pagos_sum_monto']
            : ($this->relationLoaded('pagos') ? $this->pagos->sum('monto') : $this->pagos()->sum('monto'));

        return CalculadoraTotalesDocumento::pesos(CalculadoraTotalesDocumento::centavos($suma ?? 0));
    }

    public function saldoPendiente(): string
    {
        return CalculadoraTotalesDocumento::pesos(
            CalculadoraTotalesDocumento::centavos($this->total) - CalculadoraTotalesDocumento::centavos($this->totalPagado())
        );
    }

    public function tienePagos(): bool
    {
        if (array_key_exists('pagos_count', $this->attributes)) {
            return $this->attributes['pagos_count'] > 0;
        }

        return $this->relationLoaded('pagos') ? $this->pagos->isNotEmpty() : $this->pagos()->exists();
    }

    /**
     * Lo que le queda al usuario por la cotización completa: importe de cada
     * línea (ya neto de descuentos, sin IVA) menos su costo al venderse. Las
     * líneas sin costo (libres o de un artículo que no lo tenía) no se suman y
     * la marcan como parcial; si ninguna lo tiene, la utilidad no está
     * disponible (null, distinto de 0).
     *
     * @return array{utilidad: string|null, parcial: bool}
     */
    public function utilidadVenta(): array
    {
        $conCosto = $this->lineas->filter(fn (CotizacionLinea $linea) => $linea->costo_unitario !== null);

        if ($conCosto->isEmpty()) {
            return ['utilidad' => null, 'parcial' => false];
        }

        $centavos = $conCosto->sum(fn (CotizacionLinea $linea) => CalculadoraTotalesDocumento::centavos($linea->importe)
            - CalculadoraTotalesDocumento::centavos($linea->costo_unitario) * $linea->cantidad);

        return [
            'utilidad' => CalculadoraTotalesDocumento::pesos($centavos),
            'parcial' => $conCosto->count() < $this->lineas->count(),
        ];
    }

    public function tieneAnticipo(): bool
    {
        return $this->relationLoaded('pagos')
            ? $this->pagos->contains('tipo', TipoPago::Anticipo)
            : $this->pagos()->where('tipo', TipoPago::Anticipo)->exists();
    }

    /**
     * Una cotización facturada ya no cambia: la factura salió de ella.
     */
    public function esEditable(): bool
    {
        return $this->estado->esEditable() && ! $this->estaFacturada();
    }

    /**
     * Usa factura_vigente_exists cuando el listado lo precargó con withExists().
     */
    public function estaFacturada(): bool
    {
        if (array_key_exists('factura_vigente_exists', $this->attributes)) {
            return (bool) $this->attributes['factura_vigente_exists'];
        }

        return $this->relationLoaded('facturaVigente') ? $this->facturaVigente !== null : $this->facturaVigente()->exists();
    }

    /**
     * Por estado y sin factura vigente. Las líneas se revisan aparte
     * (motivoNoFacturable).
     */
    public function esFacturable(): bool
    {
        return in_array($this->estado, self::ESTADOS_FACTURABLES, true) && ! $this->estaFacturada();
    }

    /**
     * Líneas que no pueden pasar a una factura: las libres y las de artículos
     * eliminados (el CFDI necesita las claves SAT del artículo).
     *
     * @return Collection<int, CotizacionLinea>
     */
    public function lineasNoFacturables(): Collection
    {
        $this->loadMissing('lineas.articulo');

        return $this->lineas->filter(fn (CotizacionLinea $linea) => $linea->articulo === null || $linea->articulo->trashed())->values();
    }

    /**
     * Por qué no se puede facturar; null si se puede.
     */
    public function motivoNoFacturable(): ?string
    {
        if (! in_array($this->estado, self::ESTADOS_FACTURABLES, true)) {
            return 'Una cotización en '.mb_strtolower($this->estado->etiqueta()).' no se puede facturar: envíala primero.';
        }

        $this->loadMissing('facturaVigente');

        if ($this->facturaVigente !== null) {
            return "Esta cotización ya tiene la factura {$this->facturaVigente->folioVisible()}.";
        }

        $lineas = $this->lineasNoFacturables();

        if ($lineas->isNotEmpty()) {
            return 'Tiene líneas que no vienen del catálogo ('.$lineas->pluck('orden')->implode(', ').'). Corrígelas para poder facturar.';
        }

        return null;
    }

    public function puedeEliminarse(): bool
    {
        return $this->esEditable() && ! $this->tienePagos();
    }

    public function puedeRegistrarPago(): bool
    {
        return $this->estado === EstadoCotizacion::Enviada
            && CalculadoraTotalesDocumento::centavos($this->saldoPendiente()) > 0;
    }

    /**
     * Monto que se registraría para un pago: el elegido en un anticipo, el
     * saldo pendiente en saldo y pago total (el que venga se ignora).
     */
    public function montoDePago(TipoPago $tipo, float|string|null $monto): string
    {
        return $tipo === TipoPago::Anticipo
            ? CalculadoraTotalesDocumento::pesos(CalculadoraTotalesDocumento::centavos($monto))
            : $this->saldoPendiente();
    }

    /**
     * Por qué no se puede registrar ese pago; null si se puede. La usan el
     * Form Request y el controlador (otra vez, con la fila bloqueada).
     */
    public function motivoRechazoPago(TipoPago $tipo, float|string|null $monto): ?string
    {
        if (! $this->puedeRegistrarPago()) {
            return $this->estado === EstadoCotizacion::Enviada
                ? 'La cotización ya está pagada por completo.'
                : 'Solo se registran pagos en una cotización enviada.';
        }

        return match (true) {
            $tipo === TipoPago::Anticipo && $this->tieneAnticipo() => 'La cotización ya tiene un anticipo: registra el saldo.',
            $tipo === TipoPago::PagoTotal && $this->tieneAnticipo() => 'La cotización ya tiene un anticipo: registra el saldo.',
            $tipo === TipoPago::Saldo && ! $this->tieneAnticipo() => 'Sin anticipo previo, registra el pago total.',
            CalculadoraTotalesDocumento::centavos($this->montoDePago($tipo, $monto)) > CalculadoraTotalesDocumento::centavos($this->saldoPendiente()) => 'El pago no puede ser mayor al saldo pendiente ($'.number_format((float) $this->saldoPendiente(), 2).').',
            default => null,
        };
    }

    public function puedeEntregarse(): bool
    {
        return $this->estado === EstadoCotizacion::Pagada;
    }

    /**
     * Pasa de borrador a enviada. En cualquier otro estado solo registra el
     * movimiento: reenviar reinicia el plazo de caducidad.
     */
    public function marcarEnviada(): void
    {
        if ($this->estado === EstadoCotizacion::Borrador) {
            $this->estado = EstadoCotizacion::Enviada;
            $this->save();

            return;
        }

        $this->touch();
    }

    /**
     * Fecha en que el comando de caducidad la borrará; null si no caduca
     * (estado no editable o con pagos).
     */
    public function caducaEl(): ?CarbonImmutable
    {
        if (! $this->puedeEliminarse() || $this->updated_at === null) {
            return null;
        }

        return $this->updated_at->toImmutable()->addDays(self::DIAS_CADUCIDAD);
    }

    /**
     * Días calendario en la zona del negocio entre hoy y el borrado (0 = hoy).
     */
    public function diasParaCaducar(): ?int
    {
        $caducaEl = $this->caducaEl();

        if ($caducaEl === null) {
            return null;
        }

        $zona = config('app.zona_negocio');
        $hoy = CarbonImmutable::now($zona)->startOfDay();

        return max(0, (int) $hoy->diffInDays($caducaEl->setTimezone($zona)->startOfDay(), false));
    }

    public function mostrarAvisoCaducidad(): bool
    {
        $dias = $this->diasParaCaducar();

        return $dias !== null && $dias <= self::DIAS_AVISO_CADUCIDAD;
    }

    /**
     * "Se elimina en 5 días" / "Se elimina mañana" / "Se elimina hoy".
     */
    public function textoCaducidad(): string
    {
        return match ($dias = $this->diasParaCaducar()) {
            null => '',
            0 => 'Se elimina hoy',
            1 => 'Se elimina mañana',
            default => "Se elimina en {$dias} días",
        };
    }

    /**
     * "COT-0012".
     *
     * @return Attribute<string, never>
     */
    protected function folioFormateado(): Attribute
    {
        return Attribute::get(fn (): string => 'COT-'.str_pad((string) $this->folio, 4, '0', STR_PAD_LEFT));
    }

    /**
     * Aplica los filtros del listado (combinados con Y). Los vacíos se ignoran.
     *
     * @param  Builder<self>  $consulta
     *                                   El texto busca en el folio (si parece uno), la razón social, el nombre
     *                                   comercial y el RFC del cliente. El estado "por_caducar" no es un estado:
     *                                   son las que ya muestran el aviso de caducidad.
     * @param  array{texto?: string, folio?: int|null, estado?: string, desde?: CarbonImmutable|null, hasta?: CarbonImmutable|null}  $filtros
     */
    #[Scope]
    protected function filtrar(Builder $consulta, array $filtros): void
    {
        $texto = trim($filtros['texto'] ?? '');
        $folio = $filtros['folio'] ?? null;
        $estado = $filtros['estado'] ?? '';

        if ($texto !== '') {
            $rfc = strtoupper((string) preg_replace('/\s+/', '', $texto));

            $consulta->where(function (Builder $coincidencias) use ($texto, $rfc, $folio) {
                $coincidencias->whereHas('cliente', fn (Builder $clientes) => $clientes->withTrashed()->where(
                    fn (Builder $datos) => $datos->where('razon_social', 'like', "%{$texto}%")
                        ->orWhere('nombre_comercial', 'like', "%{$texto}%")
                        ->orWhere('rfc', 'like', "%{$rfc}%")
                ));

                if ($folio !== null) {
                    $coincidencias->orWhere('folio', $folio);
                }
            });
        }

        if ($estado === self::POR_CADUCAR) {
            $consulta->porCaducar();
        } elseif ($estado === self::FACTURADAS) {
            $consulta->facturadas();
        } elseif ($estado === self::POR_FACTURAR) {
            $consulta->porFacturar();
        } elseif ($estado !== '') {
            $consulta->where('estado', $estado);
        }

        if (($filtros['desde'] ?? null) !== null) {
            $consulta->where('created_at', '>=', $filtros['desde']->utc());
        }

        if (($filtros['hasta'] ?? null) !== null) {
            $consulta->where('created_at', '<=', $filtros['hasta']->utc());
        }
    }

    /**
     * Las que ya muestran el aviso de caducidad (mostrarAvisoCaducidad()):
     * movida el día D, se borra el D + 30 y el aviso sale si D + 30 − hoy ≤ 7,
     * es decir, si el último movimiento fue antes del inicio de hoy − 22
     * (días calendario en la zona del negocio).
     *
     * @param  Builder<self>  $consulta
     */
    #[Scope]
    protected function porCaducar(Builder $consulta): void
    {
        $limite = CarbonImmutable::now(config('app.zona_negocio'))->startOfDay()
            ->subDays(self::DIAS_CADUCIDAD - self::DIAS_AVISO_CADUCIDAD - 1);

        $consulta->whereIn('estado', [EstadoCotizacion::Borrador, EstadoCotizacion::Enviada])
            ->doesntHave('pagos')
            ->doesntHave('facturaVigente')
            ->where('updated_at', '<', $limite->utc());
    }

    /**
     * Las que el comando de caducidad borra: editables, sin pagos, sin
     * factura y sin movimiento en DIAS_CADUCIDAD días.
     *
     * @param  Builder<self>  $consulta
     */
    #[Scope]
    protected function vencidas(Builder $consulta): void
    {
        $consulta->whereIn('estado', [EstadoCotizacion::Borrador, EstadoCotizacion::Enviada])
            ->doesntHave('pagos')
            ->doesntHave('facturaVigente')
            ->where('updated_at', '<', now()->subDays(self::DIAS_CADUCIDAD));
    }

    /**
     * @param  Builder<self>  $consulta
     */
    #[Scope]
    protected function facturadas(Builder $consulta): void
    {
        $consulta->has('facturaVigente');
    }

    /**
     * Se pueden facturar por estado y aún no tienen factura. Incluye las que
     * tienen líneas libres, para que se vean y se corrijan.
     *
     * @param  Builder<self>  $consulta
     */
    #[Scope]
    protected function porFacturar(Builder $consulta): void
    {
        $consulta->whereIn('estado', self::ESTADOS_FACTURABLES)->doesntHave('facturaVigente');
    }

    /**
     * Todas sus líneas vienen de un artículo que sigue en el catálogo (la
     * misma regla que lineasNoFacturables, en SQL).
     *
     * @param  Builder<self>  $consulta
     */
    #[Scope]
    protected function soloLineasDeCatalogo(Builder $consulta): void
    {
        $consulta->whereDoesntHave('lineas', fn (Builder $lineas) => $lineas->where(
            fn (Builder $noFacturables) => $noFacturables->whereNull('articulo_id')
                ->orWhereHas('articulo', fn (Builder $articulos) => $articulos->onlyTrashed())
        ));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado' => EstadoCotizacion::class,
            'descuento_global_tipo' => TipoDescuento::class,
            'descuento_global_valor' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total_descuento' => 'decimal:2',
            'base_iva_16' => 'decimal:2',
            'total_iva_16' => 'decimal:2',
            'base_iva_0' => 'decimal:2',
            'base_exento' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }
}
