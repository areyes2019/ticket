<?php

namespace App\Http\Controllers;

use App\Enums\EstadoOrdenTrabajo;
use App\Models\Pedido;
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
            ->where('estado', EstadoOrdenTrabajo::EnProceso->value)
            ->with(['pedido.lineas', 'lineas'])
            ->orderBy(Pedido::select('folio')->whereColumn('pedidos.id', 'ordenes_trabajo.pedido_id'))
            ->get();

        return view('ordenes-trabajo.imprimir', [
            'titulo' => 'Hoja de producción',
            'ordenes' => $ordenes,
            'esHojaProduccion' => true,
        ]);
    }
}
