<?php

namespace App\Services\Whatsapp\Claude;

use RuntimeException;

/**
 * Claude no contestó. $pasajera: saturado, caído o sin conexión; vale la pena
 * reintentar (regla 25). Si no, la petición está mal y pasa a humano.
 */
class ClaudeException extends RuntimeException
{
    public function __construct(string $mensaje, public readonly bool $pasajera = false, ?\Throwable $previa = null)
    {
        parent::__construct($mensaje, 0, $previa);
    }
}
