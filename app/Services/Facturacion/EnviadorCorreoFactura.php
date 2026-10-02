<?php

namespace App\Services\Facturacion;

use App\Mail\FacturaMail;
use App\Models\Factura;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Correo síncrono con el XML (pedido en vivo a facturapi.io) y el PDF. Lo
 * usan el envío manual de una factura y la autofactura de pedidos.
 */
class EnviadorCorreoFactura
{
    public function __construct(private FacturapiCliente $facturapi) {}

    /**
     * Si el XML no llega, no se envía nada.
     *
     * @param  list<string>  $destinatarios
     *
     * @throws FacturapiException si facturapi.io no entrega el XML
     * @throws Throwable si el correo no sale (ya queda en el log)
     */
    public function enviar(Factura $factura, array $destinatarios): void
    {
        $xml = $this->facturapi->descargarXml($factura->facturapi_invoice_id, $factura->id);

        try {
            Mail::to($destinatarios)->send(new FacturaMail($factura, $xml));
        } catch (Throwable $error) {
            Log::error('No se pudo enviar la factura por correo.', ['factura' => $factura->id, 'error' => $error->getMessage()]);

            throw $error;
        }
    }
}
