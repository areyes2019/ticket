<?php

namespace App\Http\Requests;

use App\Models\Cotizacion;
use App\Models\CotizacionLinea;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Timbrado directo desde la vista previa de una cotización (spec 020). El
 * navegador solo elige uso de CFDI, método y forma de pago; cliente, descuento
 * global y líneas salen de la cotización tal como están (el descuento de cada
 * línea, dentro de su precio: 023), y lo que llegue en esos campos se
 * ignora. Después se valida con todas las reglas de una factura nueva.
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
            // El descuento de cada línea va dentro de su precio (023).
            'lineas' => $cotizacion->lineas->map(fn (CotizacionLinea $linea) => [
                ...$linea->datosParaFactura(),
                'cantidad' => (string) $linea->cantidad,
            ])->all(),
        ]);

        parent::prepareForValidation();
    }
}
