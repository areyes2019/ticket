<?php

namespace App\Models;

use App\Enums\TasaIva;
use App\Enums\TipoDescuento;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * descripcion, modelo y precio_unitario son copias tomadas al capturar la
 * línea (el precio se precarga con el costo del artículo). Si el artículo
 * cambia después, la orden no cambia; y cambiar el precio aquí no toca el
 * catálogo.
 */
#[Fillable([
    'orden',
    'articulo_id',
    'cantidad',
    'descripcion',
    'modelo',
    'precio_unitario',
    'descuento_tipo',
    'descuento_valor',
    'tasa_iva',
    'importe',
    'iva_importe',
])]
class OrdenCompraLinea extends Model
{
    protected $table = 'orden_compra_lineas';

    /**
     * @return BelongsTo<OrdenCompra, $this>
     */
    public function ordenCompra(): BelongsTo
    {
        return $this->belongsTo(OrdenCompra::class);
    }

    /**
     * Incluye los artículos eliminados después de guardarse la línea.
     *
     * @return BelongsTo<Articulo, $this>
     */
    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class)->withTrashed();
    }

    /**
     * "10%" o "$5.50"; vacío sin descuento.
     */
    public function descuentoTexto(): string
    {
        if ($this->descuento_tipo === null || (float) $this->descuento_valor === 0.0) {
            return '';
        }

        return $this->descuento_tipo === TipoDescuento::Porcentaje
            ? rtrim(rtrim($this->descuento_valor, '0'), '.').'%'
            : '$'.number_format((float) $this->descuento_valor, 2);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'precio_unitario' => 'decimal:2',
            'descuento_tipo' => TipoDescuento::class,
            'descuento_valor' => 'decimal:2',
            'tasa_iva' => TasaIva::class,
            'importe' => 'decimal:2',
            'iva_importe' => 'decimal:2',
        ];
    }
}
