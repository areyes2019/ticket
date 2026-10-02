<?php

namespace App\Http\Requests;

use App\Models\Cotizacion;
use App\Models\CotizacionLinea;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Timbrado directo desde la vista previa de una cotización (spec 020). El
 * navegador solo elige uso de CFDI, método y forma de pago; cliente, descuento
 * global y líneas salen de la cotización tal como están, y lo que llegue en
 * esos campos se ignora. Después se valida con todas las reglas de una
 * factura nueva.
 */
class TimbrarCotizacionRequest extends FacturaRequest
{
    /**
     * Una cotización ajena responde 404 aunque los datos sean inválidos.
     */
    public function authorize(): bool|Response
    {
        return Gate::inspect('view', $this->cotizacion());
    }

    public function cotizacion(): Cotizacion
    {
        return $this->route('cotizacion');
    }

    protected function prepareForValidation(): void
    {
        $cotizacion = $this->cotizacion()->loadMissing('lineas');

        $this->merge([
            'cotizacion_id' => $cotizacion->id,
            'duplicada_de_id' => null,
            'cliente_id' => $cotizacion->cliente_id,
            'descuento_global_tipo' => $cotizacion->descuento_global_tipo?->value,
            'descuento_global_valor' => $cotizacion->descuento_global_valor,
            'lineas' => $cotizacion->lineas->map(fn (CotizacionLinea $linea) => [
                'articulo_id' => $linea->articulo_id,
                'cantidad' => (string) $linea->cantidad,
                'descripcion' => $linea->descripcion,
                'modelo' => $linea->modelo,
                'precio_unitario' => (string) $linea->precio_unitario,
                'descuento_tipo' => $linea->descuento_tipo?->value,
                'descuento_valor' => $linea->descuento_valor === null ? null : (string) $linea->descuento_valor,
                'tasa_iva' => $linea->tasa_iva->value,
            ])->all(),
        ]);

        parent::prepareForValidation();
    }
}
