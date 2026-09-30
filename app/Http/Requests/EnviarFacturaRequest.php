<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Mismas reglas que el envío de una cotización: 1 a 5 correos en un solo
 * campo separado por comas.
 */
class EnviarFacturaRequest extends EnviarCotizacionRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('operar', $this->route('factura'));
    }
}
