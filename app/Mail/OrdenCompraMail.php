<?php

namespace App\Mail;

use App\Mail\Concerns\AdjuntaPdf;
use App\Models\OrdenCompra;
use App\Services\OrdenesCompra\GeneradorPdfOrdenCompra;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Se envía síncrono (sin cola): en Laragon no hay un worker que atienda la
 * cola, así que un correo encolado nunca saldría.
 */
class OrdenCompraMail extends Mailable
{
    use AdjuntaPdf;

    public function __construct(public OrdenCompra $orden) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Orden de compra '.$this->orden->folio_formateado.' — '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.orden-compra');
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $pdf = app(GeneradorPdfOrdenCompra::class);

        return [$this->adjuntoPdf(fn () => $pdf->contenido($this->orden), $pdf->nombreArchivo($this->orden))];
    }
}
