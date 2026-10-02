<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Cotizacion;
use App\Models\OrdenCompra;
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
        return $this->destinoDocumento($request, 'cotizaciones', 'cotizacion', $cotizacion);
    }

    /**
     * Lo mismo para una orden de compra (?orden=8 en su bandeja).
     */
    protected function destinoOrdenCompra(Request $request, OrdenCompra $orden): string
    {
        return $this->destinoDocumento($request, 'ordenes-compra', 'orden', $orden);
    }

    private function destinoDocumento(Request $request, string $rutas, string $parametro, Cotizacion|OrdenCompra $documento): string
    {
        if ($request->input('origen') !== 'bandeja') {
            return route("{$rutas}.show", $documento);
        }

        $anterior = url()->previous();

        return str_starts_with($anterior, route("{$rutas}.index").'?')
            ? $anterior
            : route("{$rutas}.index", [$parametro => $documento->id]);
    }
}
