<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Hoja de producción (022): todas las órdenes de trabajo "En proceso", de la
 * venta más antigua a la más reciente, en una sola impresión.
 */
class HojaProduccionController extends Controller
{
    public function __invoke(Request $request): View
    {
        $ordenes = $request->user()->ordenesTrabajo()
            ->enProduccion()
            ->with(['pedido.lineas.articulo.catalogo', 'lineas'])
            ->get();

        return view('ordenes-trabajo.imprimir', [
            'titulo' => 'Hoja de producción',
            'ordenes' => $ordenes,
            'esHojaProduccion' => true,
        ]);
    }
}
