<?php

namespace App\Models;

use App\Enums\EstadoCotizacion;
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

/**
 * folio y estado no son asignables: el folio lo pone CotizacionController al
 * crear (con el contador del usuario) y el estado solo cambia por las
 * acciones del ciclo (enviar, pagar, entregar, editar).
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

    public function tieneAnticipo(): bool
    {
        return $this->relationLoaded('pagos')
            ? $this->pagos->contains('tipo', TipoPago::Anticipo)
            : $this->pagos()->where('tipo', TipoPago::Anticipo)->exists();
    }

    public function esEditable(): bool
    {
        return $this->estado->esEditable();
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
     * @param  array{cliente?: string, rfc?: string, folio?: int|null, estado?: string, desde?: CarbonImmutable|null, hasta?: CarbonImmutable|null}  $filtros
     */
    #[Scope]
    protected function filtrar(Builder $consulta, array $filtros): void
    {
        $cliente = trim($filtros['cliente'] ?? '');
        $rfc = trim($filtros['rfc'] ?? '');

        if ($cliente !== '') {
            $consulta->whereHas('cliente', fn (Builder $clientes) => $clientes->withTrashed()->where(
                fn (Builder $nombres) => $nombres->where('razon_social', 'like', "%{$cliente}%")->orWhere('nombre_comercial', 'like', "%{$cliente}%")
            ));
        }

        if ($rfc !== '') {
            $consulta->whereHas('cliente', fn (Builder $clientes) => $clientes->withTrashed()->where('rfc', 'like', "%{$rfc}%"));
        }

        if (($filtros['folio'] ?? null) !== null) {
            $consulta->where('folio', $filtros['folio']);
        }

        if (($filtros['estado'] ?? '') !== '') {
            $consulta->where('estado', $filtros['estado']);
        }

        if (($filtros['desde'] ?? null) !== null) {
            $consulta->where('created_at', '>=', $filtros['desde']->utc());
        }

        if (($filtros['hasta'] ?? null) !== null) {
            $consulta->where('created_at', '<=', $filtros['hasta']->utc());
        }
    }

    /**
     * Las que el comando de caducidad borra: editables, sin pagos y sin
     * movimiento en DIAS_CADUCIDAD días.
     *
     * @param  Builder<self>  $consulta
     */
    #[Scope]
    protected function vencidas(Builder $consulta): void
    {
        $consulta->whereIn('estado', [EstadoCotizacion::Borrador, EstadoCotizacion::Enviada])
            ->doesntHave('pagos')
            ->where('updated_at', '<', now()->subDays(self::DIAS_CADUCIDAD));
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
