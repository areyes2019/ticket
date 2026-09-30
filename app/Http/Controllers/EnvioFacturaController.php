<?php

namespace App\Http\Controllers;

use App\Http\Requests\EnviarFacturaRequest;
use App\Mail\FacturaMail;
use App\Models\Factura;
use App\Services\Facturacion\FacturapiCliente;
use App\Services\Facturacion\FacturapiException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EnvioFacturaController extends Controller
{
    /**
     * Correo síncrono con el XML (pedido en vivo a facturapi.io) y el PDF. Si
     * el XML no llega, no se envía nada. Enviar no cambia el estado.
     */
    public function correo(EnviarFacturaRequest $request, Factura $factura, FacturapiCliente $facturapi): RedirectResponse
    {
        if (! $factura->puedeEnviarse()) {
            return back()->with('error', 'Solo se envía por correo una factura timbrada.');
        }

        $destinatarios = $request->validated('destinatarios');

        try {
            $xml = $facturapi->descargarXml($factura->facturapi_invoice_id, $factura->id);
        } catch (FacturapiException) {
            return back()->withInput()->withErrors(['destinatarios' => 'No se pudo obtener el XML de facturapi.io, así que no se envió el correo. Intenta de nuevo.'], 'envio');
        }

        try {
            Mail::to($destinatarios)->send(new FacturaMail($factura, $xml));
        } catch (Throwable $error) {
            Log::error('No se pudo enviar la factura por correo.', ['factura' => $factura->id, 'error' => $error->getMessage()]);

            return back()->withInput()->withErrors(['destinatarios' => 'No se pudo enviar el correo. Revisa la configuración del servidor de correo e intenta de nuevo.'], 'envio');
        }

        return redirect()->route('facturas.show', $factura)
            ->with('exito', 'Factura enviada a '.implode(', ', $destinatarios).'.');
    }
}
