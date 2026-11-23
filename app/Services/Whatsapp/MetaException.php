<?php

namespace App\Services\Whatsapp;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Meta rechazó o no contestó una llamada. $pasajera indica si vale la pena
 * reintentar (sin conexión, 429 o 5xx).
 */
class MetaException extends RuntimeException
{
    public function __construct(string $mensaje, public readonly bool $pasajera = false, public readonly ?int $codigo = null)
    {
        parent::__construct($mensaje);
    }

    public static function desdeRespuesta(Response $respuesta, string $accion): self
    {
        $error = $respuesta->json('error') ?? [];
        $detalle = $error['error_user_msg'] ?? $error['error_data']['details'] ?? $error['message'] ?? 'sin detalle';

        return new self(
            "Meta rechazó {$accion}: {$detalle}",
            pasajera: $respuesta->status() === 429 || $respuesta->serverError(),
            codigo: isset($error['code']) ? (int) $error['code'] : null,
        );
    }

    public static function sinConexion(string $accion): self
    {
        return new self("No hubo respuesta de Meta al {$accion}.", pasajera: true);
    }
}
