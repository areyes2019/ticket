<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalizarConstanciaRequest;
use App\Services\Constancia\ConstanciaFiscalService;
use Illuminate\Http\JsonResponse;

class ConstanciaController extends Controller
{
    /**
     * Analiza la Constancia de Situación Fiscal y devuelve los datos para
     * precargar el formulario de cliente. No guarda nada.
     */
    public function __invoke(AnalizarConstanciaRequest $request, ConstanciaFiscalService $servicio): JsonResponse
    {
        $resultado = $servicio->analizar($request->file('archivo'), $request->input('qr_url'));

        $existente = $request->user()->clientes()->where('rfc', $resultado->rfc())->first();

        return response()->json([
            'fuente' => $resultado->fuente->value,
            'confianza' => $resultado->fuente->confianza(),
            'aviso' => $resultado->aviso,
            'advertencias' => $resultado->advertencias,
            'data' => $resultado->data,
            'cliente_existente' => $existente === null ? null : [
                'id' => $existente->id,
                'razon_social' => $existente->razon_social,
                'url_editar' => route('clientes.edit', $existente),
            ],
        ]);
    }
}
