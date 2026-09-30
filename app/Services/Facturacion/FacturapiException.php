<?php

namespace App\Services\Facturacion;

use App\Enums\TipoErrorTimbrado;
use RuntimeException;

/**
 * Falla de una llamada a facturapi.io. El mensaje se muestra tal cual al
 * usuario: es el de facturapi.io (datos rechazados) o uno propio (timeout,
 * conexión, configuración).
 */
class FacturapiException extends RuntimeException
{
    /**
     * @param  int|null  $estado  código HTTP de facturapi.io (null sin respuesta)
     */
    public function __construct(string $mensaje, public readonly TipoErrorTimbrado $tipo, public readonly ?int $estado = null)
    {
        parent::__construct($mensaje);
    }

    /**
     * 409: la idempotency_key ya timbró un CFDI (un intento anterior sí llegó
     * a facturapi.io aunque aquí se viera como timeout).
     */
    public function yaTimbrada(): bool
    {
        return $this->estado === 409;
    }
}
