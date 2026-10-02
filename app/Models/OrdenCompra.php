<?php

namespace App\Models;

use App\Enums\EstadoOrdenCompra;
use App\Enums\TipoDescuento;
use Carbon\CarbonImmutable;
use Database\Factories\OrdenCompraFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * El espejo de la cotización: lo que se le compra a un proveedor, a su costo.
 *
 * folio, estado, totales, cuenta_id, fecha_pago y duplicada_de_id no son
 * asignables: los ponen OrdenCompraController, OrdenCompraPagoController y
 * los métodos de este modelo.
 *
 * El pago es único, de contado y por el total: vive en cuenta_id/fecha_pago
 * (los dos nulos = sin pagar) y su egreso en Tesorería apunta a la orden con
 * documentable.
 */
#[Fillable([
    'proveedor_id',
    'descuento_global_tipo',
    'descuento_global_valor',
    'fecha_entrega_esperada',
    'observaciones',
])]
class OrdenCompra extends Model
{
    /** @use HasFactory<OrdenCompraFactory> */
    use HasFactory;

    /**
     * Str::plural no conoce el español: inferiría "orden_compras".
     */
    protected $table = 'ordenes_compra';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'estado' => 'borrador',
    ];

    /**
     * Columnas de totales que escribe la calculadora.
     */
    public const TOTALES = Cotizacion::TOTALES;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Incluye proveedores eliminados: una orden recibida ya no impide borrar
     * a su proveedor, y la orden sigue siendo un documento.
     *
     * @return BelongsTo<Proveedor, $this>
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class)->withTrashed();
    }

    /**
     * @return HasMany<OrdenCompraLinea, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(OrdenCompraLinea::class)->orderBy('orden');
    }

    /**
     * @return BelongsTo<Cuenta, $this>
     */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    /**
     * El egreso del pago, si está pagada.
     *
     * @return MorphOne<Movimiento, $this>
     */
    public function movimiento(): MorphOne
    {
        return $this->morphOne(Movimiento::class, 'documentable');
    }

    /**
     * @return BelongsTo<OrdenCompra, $this>
     */
    public function duplicadaDe(): BelongsTo
    {
        return $this->belongsTo(OrdenCompra::class, 'duplicada_de_id');
    }

    /**
     * @param  array<string, mixed>  $totales  resultado de CalculadoraTotalesDocumento::calcular()
     */
    public function aplicarTotales(array $totales): void
    {
        foreach (self::TOTALES as $columna) {
            $this->{$columna} = $totales[$columna];
        }
    }

    public function esEditable(): bool
    {
        return $this->estado->esEditable();
    }

    public function puedeEliminarse(): bool
    {
        return $this->estado === EstadoOrdenCompra::Borrador;
    }

    public function puedeRegistrarPago(): bool
    {
        return $this->estado === EstadoOrdenCompra::Enviada;
    }

    public function puedeCancelarPago(): bool
    {
        return $this->estado === EstadoOrdenCompra::Pagada;
    }

    public function puedeRecibirse(): bool
    {
        return $this->estado === EstadoOrdenCompra::Pagada;
    }

    public function estaPagada(): bool
    {
        return $this->cuenta_id !== null;
    }

    /**
     * Pasa de borrador a enviada. En cualquier otro estado no cambia nada:
     * reenviar no retrocede ni adelanta el ciclo.
     */
    public function marcarEnviada(): void
    {
        if ($this->estado === EstadoOrdenCompra::Borrador) {
            $this->estado = EstadoOrdenCompra::Enviada;
            $this->save();
        }
    }

    /**
     * Concepto del egreso en Tesorería. No se edita.
     */
    public function conceptoPago(): string
    {
        return "Pago de Orden de compra {$this->folio_formateado}";
    }

    /**
     * "OC-0015".
     *
     * @return Attribute<string, never>
     */
    protected function folioFormateado(): Attribute
    {
        return Attribute::get(fn (): string => 'OC-'.str_pad((string) $this->folio, 4, '0', STR_PAD_LEFT));
    }

    /**
     * Filtros de la bandeja, combinados con Y. Los vacíos se ignoran. Las
     * fechas son límites inclusivos ya en la zona del negocio.
     *
     * El texto busca en el nombre comercial, el contacto y el RFC del
     * proveedor (incluidos los eliminados) y, si parece un folio, también por
     * folio.
     *
     * @param  Builder<self>  $consulta
     * @param  array{texto?: string, folio?: int|null, estado?: string, desde?: CarbonImmutable|null, hasta?: CarbonImmutable|null}  $filtros
     */
    #[Scope]
    protected function filtrar(Builder $consulta, array $filtros): void
    {
        $texto = trim($filtros['texto'] ?? '');
        $folio = $filtros['folio'] ?? null;

        if ($texto !== '') {
            $rfc = strtoupper((string) preg_replace('/\s+/', '', $texto));

            $consulta->where(function (Builder $coincidencias) use ($texto, $rfc, $folio) {
                $coincidencias->whereHas('proveedor', fn (Builder $proveedores) => $proveedores->withTrashed()->where(
                    fn (Builder $datos) => $datos->where('nombre_comercial', 'like', "%{$texto}%")
                        ->orWhere('nombre_contacto', 'like', "%{$texto}%")
                        ->orWhere('rfc', 'like', "%{$rfc}%")
                ));

                if ($folio !== null) {
                    $coincidencias->orWhere('folio', $folio);
                }
            });
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado' => EstadoOrdenCompra::class,
            'fecha_entrega_esperada' => 'date',
            'fecha_pago' => 'date',
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
