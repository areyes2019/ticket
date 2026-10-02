<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Mismas reglas que el envío de la cotización (de 1 a 5 correos, bolsa
 * "envio"); solo cambia el documento que se autoriza.
 */
class EnviarOrdenCompraRequest extends EnviarCotizacionRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('operar', $this->route('ordenCompra'));
    }
}
