<?php

namespace App\Models;

use App\Enums\TasaIva;
use App\Enums\TipoDescuento;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * descripcion, modelo, precio_unitario y costo_unitario son copias del
 * artículo tomadas al guardar la línea: si el artículo cambia después, la
 * cotización no cambia. costo_unitario lo pone solo el servidor.
 *
 * A la factura no viaja el descuento de la línea (023): va escondido en el
 * precio unitario (precio_unitario_facturacion) para que el total sea el mismo
 * y la factura no muestre que hubo descuento.
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
class CotizacionLinea extends Model
{
    /**
     * @return BelongsTo<Cotizacion, $this>
     */
    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
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
     * Una línea libre es producción; una de artículo, si su catálogo lo es
     * (029). Para varias líneas, precargar articulo.catalogo.
     */
    public function esProduccion(): bool
    {
        return $this->articulo_id === null || (bool) $this->articulo?->requiereProduccion();
    }

    /**
     * La línea como llega a la factura (formulario, timbrado directo): precio
     * con el descuento de línea adentro y sin descuento propio.
     *
     * @return array{articulo_id: int|null, cantidad: int, descripcion: string, modelo: string|null, precio_unitario: string, descuento_tipo: null, descuento_valor: null, tasa_iva: string}
     */
    public function datosParaFactura(): array
    {
        return [
            'articulo_id' => $this->articulo_id,
            'cantidad' => $this->cantidad,
            'descripcion' => $this->descripcion,
            'modelo' => $this->modelo,
            'precio_unitario' => $this->precio_unitario_facturacion,
            'descuento_tipo' => null,
            'descuento_valor' => null,
            'tasa_iva' => $this->tasa_iva->value,
        ];
    }

    /**
     * Neto de la línea antes del descuento global, entre la cantidad. Sin
     * descuento de línea es el mismo precio_unitario.
     *
     * @return Attribute<string, never>
     */
    protected function precioUnitarioFacturacion(): Attribute
    {
        return Attribute::get(fn (): string => CalculadoraTotalesDocumento::precioConDescuentoDeLinea(
            $this->cantidad,
            $this->precio_unitario,
            $this->descuento_tipo?->value,
            $this->descuento_valor,
        ));
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
            'costo_unitario' => 'decimal:2',
        ];
    }
}
