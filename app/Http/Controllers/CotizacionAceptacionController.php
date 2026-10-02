<?php

namespace App\Http\Controllers;

use App\Http\Requests\AceptarCotizacionRequest;
use App\Models\Cotizacion;
use App\Services\Ventas\AceptadorCotizacion;
use Illuminate\Http\RedirectResponse;

/**
 * "Aceptar" una cotización (021): crea su venta y lleva a ella, venga del
 * detalle, de la bandeja o del dashboard: lo que sigue es cobrar.
 */
class CotizacionAceptacionController extends Controller
{
    public function store(AceptarCotizacionRequest $request, Cotizacion $cotizacion, AceptadorCotizacion $aceptador): RedirectResponse
    {
        $venta = $aceptador->aceptar($cotizacion, $request->datosCliente());

        return redirect()->route('pedidos.show', $venta)
            ->with('exito', "Cotización {$cotizacion->folio_formateado} aceptada. Se creó la venta {$venta->folio_formateado}. Registra el pago para compartir el ticket.");
    }
}
