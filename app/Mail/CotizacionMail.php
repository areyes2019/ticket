<?php

namespace App\Mail;

use App\Mail\Concerns\AdjuntaPdf;
use App\Mail\Concerns\CopiaAlNegocio;
use App\Models\Cotizacion;
use App\Services\Cotizaciones\GeneradorPdfCotizacion;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Se envía síncrono (sin cola): en Laragon no hay un worker que atienda la
 * cola, así que un correo encolado nunca saldría.
 */
class CotizacionMail extends Mailable
{
    use AdjuntaPdf, CopiaAlNegocio;

    public function __construct(public Cotizacion $cotizacion) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Cotización '.$this->cotizacion->folio_formateado.' — '.config('app.name'),
            bcc: $this->copiaAlNegocio(),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.cotizacion');
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $pdf = app(GeneradorPdfCotizacion::class);

        return [$this->adjuntoPdf(fn () => $pdf->contenido($this->cotizacion), $pdf->nombreArchivo($this->cotizacion))];
    }
}
