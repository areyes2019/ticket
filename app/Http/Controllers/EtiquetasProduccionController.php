<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Etiquetas de producción (030): una etiqueta de 60 × 30 mm por cada orden
 * "En proceso", en planilla carta de 3 × 8. El parámetro inicio (1 a 24) deja
 * en blanco las casillas ya usadas de la primera hoja.
 */
class EtiquetasProduccionController extends Controller
{
    public const POR_HOJA = 24;

    public function __invoke(Request $request): View
    {
        $inicio = filter_var($request->query('inicio'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => self::POR_HOJA, 'default' => 1],
        ]);

        $ordenes = $request->user()->ordenesTrabajo()
            ->enProduccion()
            ->with(['pedido.lineas.articulo.catalogo', 'pedido.pagos', 'pedido.cotizacion.pagos'])
            ->get();

        return view('ordenes-trabajo.etiquetas', [
            'ordenes' => $ordenes,
            'inicio' => $inicio,
            'hojas' => collect(array_fill(0, $inicio - 1, null))
                ->concat($ordenes)
                ->chunk(self::POR_HOJA),
        ]);
    }
}
