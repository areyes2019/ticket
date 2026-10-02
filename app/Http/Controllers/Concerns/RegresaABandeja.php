<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Cotizacion;
use Illuminate\Http\Request;

trait RegresaABandeja
{
    /**
     * A dónde volver tras una acción sobre la cotización: su detalle o, si se
     * hizo desde la bandeja (origen=bandeja), la bandeja con ella abierta. La
     * página anterior conserva carpeta, etiqueta y búsqueda; solo se acepta si
     * es la bandeja misma.
     */
    protected function destinoCotizacion(Request $request, Cotizacion $cotizacion): string
    {
        if ($request->input('origen') !== 'bandeja') {
            return route('cotizaciones.show', $cotizacion);
        }

        $anterior = url()->previous();

        return str_starts_with($anterior, route('cotizaciones.index').'?')
            ? $anterior
            : route('cotizaciones.index', ['cotizacion' => $cotizacion->id]);
    }
}
