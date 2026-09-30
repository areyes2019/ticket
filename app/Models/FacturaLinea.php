<?php

namespace App\Models;

use App\Enums\ObjetoImpuesto;
use App\Enums\TasaIva;
use App\Enums\TipoDescuento;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * descripcion, modelo y precio_unitario son copias editables del artículo;
 * clave_prod_serv, clave_unidad y objeto_imp son copias que pone solo el
 * servidor al guardar la línea. Si el artículo cambia después, la factura no
 * cambia.
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
    'clave_prod_serv',
    'clave_unidad',
    'objeto_imp',
])]
class FacturaLinea extends Model
{
    /**
     * @return BelongsTo<Factura, $this>
     */
    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class);
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
     * cantidad × precio, antes de cualquier descuento.
     */
    public function bruto(): string
    {
        return CalculadoraTotalesDocumento::pesos($this->cantidad * CalculadoraTotalesDocumento::centavos($this->precio_unitario));
    }

    /**
     * Descuento que viaja al CFDI: el de la línea más su parte del descuento
     * global (el CFDI no tiene descuento a nivel documento).
     */
    public function descuentoCfdi(): string
    {
        return CalculadoraTotalesDocumento::pesos(
            CalculadoraTotalesDocumento::centavos($this->bruto()) - CalculadoraTotalesDocumento::centavos($this->importe)
        );
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
            'objeto_imp' => ObjetoImpuesto::class,
        ];
    }
}
