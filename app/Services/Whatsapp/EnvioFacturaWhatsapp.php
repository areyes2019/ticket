<?php

namespace App\Services\Whatsapp;

use App\Models\Factura;
use App\Models\WhatsappConversacion;
use App\Services\Facturacion\FacturapiCliente;
use App\Services\Facturacion\GeneradorPdfFactura;

/**
 * PDF y XML de una factura timbrada por el chat (041, reglas 48 y 50).
 */
class EnvioFacturaWhatsapp
{
    public function __construct(
        private GeneradorPdfFactura $pdf,
        private FacturapiCliente $facturapi,
        private EnviadorWhatsapp $enviador,
    ) {}

    public function enviar(WhatsappConversacion $conversacion, Factura $factura, string $autor): void
    {
        $enlace = ['factura_id' => $factura->id];

        $this->enviador->documento($conversacion, $this->pdf->contenido($factura), $factura->nombreArchivo('pdf'), 'application/pdf', $autor, pie: 'Factura '.$factura->folioVisible(), datos: $enlace);
        $this->enviador->documento($conversacion, $this->facturapi->descargarXml($factura->facturapi_invoice_id, $factura->id), $factura->nombreArchivo('xml'), 'text/xml', $autor, datos: $enlace);
    }
}
