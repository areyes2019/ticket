<?php

namespace App\Models;

use App\Enums\EstadoFactura;
use App\Enums\EstadoPedido;
use App\Enums\TipoDescuento;
use App\Models\Concerns\CalculaUtilidadVenta;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use Carbon\CarbonImmutable;
use Database\Factories\PedidoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * La venta (019, 021): de mostrador, o nacida de una cotización aceptada
 * (cotizacion_id y cliente_id, que escribe solo AceptadorCotizacion). En
 * pantalla se llama "Venta"; el folio sigue siendo PED-0042. El cliente vive
 * en el propio pedido, sin RFC; cliente_id solo apunta al cliente fiscal de la
 * cotización.
 *
 * folio, estado, totales, entregado_en y los datos de la autofactura no son
 * asignables: los escriben el controlador y los métodos de este modelo.
 * pendiente/anticipo/pagado se derivan de los pagos (recalcularEstado());
 * entregado solo lo escriben marcarEntregado() y deshacerEntrega().
 */
#[Fillable([
    'cliente_nombre',
    'cliente_telefono',
    'cliente_correo',
    'descuento_global_tipo',
    'descuento_global_valor',
])]
class Pedido extends Model
{
    use CalculaUtilidadVenta;

    /** @use HasFactory<PedidoFactory> */
    use HasFactory;

    protected $table = 'pedidos';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'estado' => 'pendiente',
    ];

    /**
     * Columnas de totales que escribe la calculadora.
     */
    public const TOTALES = ['subtotal', 'total_descuento', 'base_iva_16', 'total_iva_16', 'base_iva_0', 'base_exento', 'total'];

    /**
     * Ventana del servidor para deshacer una entrega sin cobro. El botón solo
     * se ofrece 10 segundos; esto evita que una pestaña olvidada la revierta
     * mañana.
     */
    public const MINUTOS_DESHACER_ENTREGA = 5;

    /**
     * Segundos que se ofrece el botón "Deshacer".
     */
    public const SEGUNDOS_BOTON_DESHACER = 10;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * La cotización aceptada de la que nació, si no es de mostrador.
     *
     * @return BelongsTo<Cotizacion, $this>
     */
    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    /**
     * El cliente fiscal de esa cotización, aunque se haya eliminado.
     *
     * @return BelongsTo<Cliente, $this>
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class)->withTrashed();
    }

    public function esDeCotizacion(): bool
    {
        return $this->cotizacion_id !== null;
    }

    /**
     * La factura vigente de su cotización: una sola factura entre la
     * cotización aceptada y su venta (021).
     */
    public function facturaDeLaCotizacion(): ?Factura
    {
        return $this->esDeCotizacion() ? $this->cotizacion?->facturaVigente : null;
    }

    /**
     * Consecutivo por usuario que nunca se reutiliza (mismo mecanismo que la
     * cotización): es el "No. de ticket". Lo usan el alta de mostrador y la
     * aceptación de una cotización; va dentro de su transacción.
     */
    public static function siguienteFolio(User $user): int
    {
        $bloqueado = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        $folio = max($bloqueado->ultimo_folio_pedido, (int) $bloqueado->pedidos()->max('folio')) + 1;

        $bloqueado->forceFill(['ultimo_folio_pedido' => $folio])->save();

        return $folio;
    }

    /**
     * @return HasMany<PedidoLinea, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(PedidoLinea::class)->orderBy('orden');
    }

    /**
     * @return HasMany<PedidoPago, $this>
     */
    public function pagos(): HasMany
    {
        return $this->hasMany(PedidoPago::class)->orderBy('fecha_pago')->orderBy('id');
    }

    /**
     * @return HasMany<Factura, $this>
     */
    public function facturas(): HasMany
    {
        return $this->hasMany(Factura::class);
    }

    /**
     * La autofactura en curso o timbrada: cualquiera que no esté cancelada.
     *
     * @return HasOne<Factura, $this>
     */
    public function facturaVigente(): HasOne
    {
        return $this->hasOne(Factura::class)->where('estado', '!=', EstadoFactura::Cancelada->value);
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

    /**
     * Nunca negativo.
     */
    public function saldoPendiente(): string
    {
        return CalculadoraTotalesDocumento::pesos(max(0,
            CalculadoraTotalesDocumento::centavos($this->total) - CalculadoraTotalesDocumento::centavos($this->totalPagado())
        ));
    }

    public function tieneSaldo(): bool
    {
        return CalculadoraTotalesDocumento::centavos($this->saldoPendiente()) > 0;
    }

    public function tienePagos(): bool
    {
        return $this->relationLoaded('pagos') ? $this->pagos->isNotEmpty() : $this->pagos()->exists();
    }

    public function esEditable(): bool
    {
        return $this->estado->esEditable();
    }

    public function puedeEliminarse(): bool
    {
        return $this->estado === EstadoPedido::Pendiente && ! $this->tienePagos();
    }

    /**
     * En cualquier estado mientras quede saldo: en entregado sirve para
     * recapturar un cobro que se borró por error.
     */
    public function puedeRegistrarPago(): bool
    {
        return $this->tieneSaldo();
    }

    /**
     * Un CFDI PUE ya dice que el pedido se pagó.
     */
    public function puedeEliminarPago(): bool
    {
        return $this->facturaTimbrada() === null;
    }

    /**
     * El ticket es el comprobante de un dinero que ya entró. Vale igual para
     * "Avisar que está listo".
     */
    public function puedeCompartirTicket(): bool
    {
        return $this->tienePagos();
    }

    public function estaEntregado(): bool
    {
        return $this->estado === EstadoPedido::Entregado;
    }

    /**
     * Solo la entrega que no cobró nada, dentro de la ventana del servidor.
     */
    public function puedeDeshacerEntrega(): bool
    {
        return $this->estaEntregado()
            && $this->entregado_en !== null
            && $this->entregado_en->greaterThan(now()->subMinutes(self::MINUTOS_DESHACER_ENTREGA))
            && ! $this->pagos()->where('registrado_al_entregar', true)->exists();
    }

    /**
     * Deriva pendiente/anticipo/pagado de la suma de pagos (no guarda). Nunca
     * toca un pedido entregado. Al llegar a pagado nace el token de
     * autofactura, y se conserva aunque después baje el saldo.
     */
    public function recalcularEstado(): void
    {
        if ($this->estaEntregado()) {
            return;
        }

        $this->estado = match (true) {
            ! $this->tieneSaldo() => EstadoPedido::Pagado,
            CalculadoraTotalesDocumento::centavos($this->totalPagado()) > 0 => EstadoPedido::Anticipo,
            default => EstadoPedido::Pendiente,
        };

        if ($this->estado === EstadoPedido::Pagado && $this->autofactura_token === null) {
            $this->autofactura_token = Str::random(64);
        }
    }

    /**
     * No guarda.
     */
    public function marcarEntregado(): void
    {
        $this->estado = EstadoPedido::Entregado;
        $this->entregado_en = now();
    }

    /**
     * No guarda.
     */
    public function deshacerEntrega(): void
    {
        $this->estado = EstadoPedido::Pendiente;
        $this->entregado_en = null;
        $this->recalcularEstado();
    }

    /**
     * La factura ya timbrada (o timbrada y cancelada): un pedido, una factura.
     */
    public function facturaTimbrada(): ?Factura
    {
        return $this->facturas()
            ->whereIn('estado', [EstadoFactura::Timbrada->value, EstadoFactura::Cancelada->value])
            ->latest('id')
            ->first();
    }

    /**
     * Último instante del mes de la venta, en la zona del negocio.
     */
    public function autofacturaVenceEl(): CarbonImmutable
    {
        return $this->created_at->toImmutable()->setTimezone(config('app.zona_negocio'))->endOfMonth();
    }

    /**
     * Por qué el enlace de autofactura no sirve; null si sirve.
     */
    public function motivoAutofacturaNoDisponible(): ?string
    {
        if ($this->facturaTimbrada() !== null || $this->facturaDeLaCotizacion() !== null) {
            return 'Esta venta ya se facturó.';
        }

        if (CarbonImmutable::now()->greaterThan($this->autofacturaVenceEl())) {
            return 'El enlace para facturar venció el '.$this->autofacturaVenceEl()->format('d/m/Y').'. Comunícate con el negocio.';
        }

        if ($this->autofactura_token === null || $this->tieneSaldo()) {
            return 'Esta venta todavía no está pagada por completo.';
        }

        return null;
    }

    public function urlAutofactura(): ?string
    {
        return $this->autofactura_token === null ? null : route('autofactura.show', $this->autofactura_token);
    }

    /**
     * "PED-0042".
     *
     * @return Attribute<string, never>
     */
    protected function folioFormateado(): Attribute
    {
        return Attribute::get(fn (): string => 'PED-'.$this->numero_ticket);
    }

    /**
     * "0042": el No. de ticket que imprimen el ticket y la etiqueta.
     *
     * @return Attribute<string, never>
     */
    protected function numeroTicket(): Attribute
    {
        return Attribute::get(fn (): string => str_pad((string) $this->folio, 4, '0', STR_PAD_LEFT));
    }

    /**
     * "449 123 4567" (se guarda +524491234567).
     *
     * @return Attribute<string, never>
     */
    protected function telefonoLegible(): Attribute
    {
        return Attribute::get(function (): string {
            $digitos = substr((string) preg_replace('/\D/', '', (string) $this->cliente_telefono), -10);

            return strlen($digitos) === 10
                ? substr($digitos, 0, 3).' '.substr($digitos, 3, 3).' '.substr($digitos, 6)
                : (string) $this->cliente_telefono;
        });
    }

    /**
     * Filtros del listado, combinados con Y. Los vacíos se ignoran.
     *
     * @param  Builder<self>  $consulta
     * @param  array{folio?: int|null, cliente?: string, telefono?: string, estado?: string, origen?: string, desde?: CarbonImmutable|null, hasta?: CarbonImmutable|null}  $filtros
     */
    #[Scope]
    protected function filtrar(Builder $consulta, array $filtros): void
    {
        if (($filtros['folio'] ?? null) !== null) {
            $consulta->where('folio', $filtros['folio']);
        }

        if (($filtros['cliente'] ?? '') !== '') {
            $consulta->where('cliente_nombre', 'like', '%'.$filtros['cliente'].'%');
        }

        if (($filtros['telefono'] ?? '') !== '') {
            $consulta->where('cliente_telefono', 'like', '%'.$filtros['telefono'].'%');
        }

        if (($filtros['estado'] ?? '') !== '') {
            $consulta->where('estado', $filtros['estado']);
        }

        if (($filtros['origen'] ?? '') === 'mostrador') {
            $consulta->whereNull('cotizacion_id');
        } elseif (($filtros['origen'] ?? '') === 'cotizacion') {
            $consulta->whereNotNull('cotizacion_id');
        }

        if (($filtros['desde'] ?? null) !== null) {
            $consulta->where('created_at', '>=', $filtros['desde']->utc());
        }

        if (($filtros['hasta'] ?? null) !== null) {
            $consulta->where('created_at', '<=', $filtros['hasta']->utc());
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
            'estado' => EstadoPedido::class,
            'descuento_global_tipo' => TipoDescuento::class,
            'descuento_global_valor' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total_descuento' => 'decimal:2',
            'base_iva_16' => 'decimal:2',
            'total_iva_16' => 'decimal:2',
            'base_iva_0' => 'decimal:2',
            'base_exento' => 'decimal:2',
            'total' => 'decimal:2',
            'entregado_en' => 'immutable_datetime',
        ];
    }
}
