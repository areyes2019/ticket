<?php

namespace App\Http\Controllers;

use App\Http\Requests\EnviarFacturaRequest;
use App\Models\Factura;
use App\Services\Facturacion\EnviadorCorreoFactura;
use App\Services\Facturacion\FacturapiException;
use Illuminate\Http\RedirectResponse;
use Throwable;

class EnvioFacturaController extends Controller
{
    /**
     * Correo síncrono con el XML y el PDF. Si el XML no llega, no se envía
     * nada. Enviar no cambia el estado.
     */
    public function correo(EnviarFacturaRequest $request, Factura $factura, EnviadorCorreoFactura $enviador): RedirectResponse
    {
        if (! $factura->puedeEnviarse()) {
            return back()->with('error', 'Solo se envía por correo una factura timbrada.');
        }

        $destinatarios = $request->validated('destinatarios');

        try {
            $enviador->enviar($factura, $destinatarios);
        } catch (FacturapiException) {
            return back()->withInput()->withErrors(['destinatarios' => 'No se pudo obtener el XML de facturapi.io, así que no se envió el correo. Intenta de nuevo.'], 'envio');
        } catch (Throwable) {
            return back()->withInput()->withErrors(['destinatarios' => 'No se pudo enviar el correo. Revisa la configuración del servidor de correo e intenta de nuevo.'], 'envio');
        }

        return redirect()->route('facturas.show', $factura)
            ->with('exito', 'Factura enviada a '.implode(', ', $destinatarios).'.');
    }
}
