<?php

namespace App\Models;

use App\Enums\TasaIva;
use App\Enums\TipoDescuento;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mismas reglas que CotizacionLinea: descripcion, modelo, precio_unitario y
 * costo_unitario son copias del artículo al guardar la línea. Sin
 * articulo_id es una línea libre: no mueve inventario ni tiene costo.
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
    'costo_unitario',
])]
class PedidoLinea extends Model
{
    /**
     * @return BelongsTo<Pedido, $this>
     */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
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
     * Precio unitario con IVA, el número que conoce el cliente. Es una
     * referencia: lo que se suma es importeConIva().
     */
    public function precioConIva(): string
    {
        return CalculadoraTotalesDocumento::pesos((int) round(
            CalculadoraTotalesDocumento::centavos($this->precio_unitario) * (1 + $this->tasa_iva->factor())
        ));
    }

    /**
     * Lo que se cobra por la línea: importe neto más su IVA.
     */
    public function importeConIva(): string
    {
        return CalculadoraTotalesDocumento::pesos(
            CalculadoraTotalesDocumento::centavos($this->importe) + CalculadoraTotalesDocumento::centavos($this->iva_importe)
        );
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
            'costo_unitario' => 'decimal:2',
        ];
    }
}
