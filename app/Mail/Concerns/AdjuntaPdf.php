<?php

namespace App\Mail\Concerns;

use Closure;
use Illuminate\Mail\Attachment;

/**
 * Adjunto PDF generado al vuelo: el contenido solo se genera al enviar.
 */
trait AdjuntaPdf
{
    /**
     * @param  Closure(): string  $contenido
     */
    protected function adjuntoPdf(Closure $contenido, string $nombre): Attachment
    {
        return Attachment::fromData($contenido, $nombre)->withMime('application/pdf');
    }
}
