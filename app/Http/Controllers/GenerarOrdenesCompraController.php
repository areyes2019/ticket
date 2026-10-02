<?php

namespace App\Http\Controllers;

use App\Services\Inventario\GeneradorOrdenesReposicion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Generar órdenes de compra" de Existencias: un borrador por proveedor con
 * los artículos por pedir. Con una sola orden lleva a su detalle para
 * revisarla; con varias, a la bandeja.
 */
class GenerarOrdenesCompraController extends Controller
{
    public function __invoke(Request $request, GeneradorOrdenesReposicion $generador): RedirectResponse
    {
        ['ordenes' => $ordenes, 'omitidos' => $omitidos] = $generador->generar($request->user());

        $avisoOmitidos = $omitidos === [] ? '' : ' Sin orden: '.collect($omitidos)
            ->map(fn (array $omitido) => "{$omitido['articulo']->modelo} ({$omitido['motivo']})")
            ->implode(', ').'.';

        if ($ordenes === []) {
            return redirect()->route('existencias.index')->with('error', 'No hay artículos por pedir.'.$avisoOmitidos);
        }

        if (count($ordenes) === 1) {
            $orden = $ordenes[0];

            return redirect()->route('ordenes-compra.show', $orden)
                ->with('exito', "Orden de compra {$orden->folio_formateado} creada en borrador. Revísala antes de enviarla.".$avisoOmitidos);
        }

        $folios = collect($ordenes)->map(fn ($orden) => $orden->folio_formateado)->implode(', ');

        return redirect()->route('ordenes-compra.index')
            ->with('exito', 'Se crearon '.count($ordenes)." órdenes de compra en borrador: {$folios}. Revísalas antes de enviarlas.".$avisoOmitidos);
    }
}
