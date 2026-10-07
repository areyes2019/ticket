<?php

namespace App\Mail;

use App\Mail\Concerns\CopiaAlNegocio;
use App\Models\Factura;
use App\Services\Facturacion\GeneradorPdfFactura;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Se envía síncrono (sin cola), como la cotización. El XML llega ya
 * descargado de facturapi.io: si no se pudo obtener, el correo no se arma.
 */
class FacturaMail extends Mailable
{
    use CopiaAlNegocio;

    public function __construct(public Factura $factura, public string $xml) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Factura '.$this->factura->folioVisible().' — '.config('app.name'),
            bcc: $this->copiaAlNegocio(),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.factura', with: ['receptor' => $this->factura->receptor()]);
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $pdf = app(GeneradorPdfFactura::class);

        return [
            Attachment::fromData(fn () => $this->xml, $this->factura->nombreArchivo('xml'))
                ->withMime('application/xml'),
            Attachment::fromData(fn () => $pdf->contenido($this->factura), $this->factura->nombreArchivo('pdf'))
                ->withMime('application/pdf'),
        ];
    }
}
