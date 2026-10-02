<?php

namespace App\Exceptions;

use App\Models\Cuenta;
use RuntimeException;

/**
 * Una regla de Tesorería que solo se puede revisar con la cuenta bloqueada.
 * Cada controlador la convierte en la respuesta que le toca (error del
 * diálogo o aviso de la página).
 */
abstract class OperacionTesoreriaRechazada extends RuntimeException
{
    public function __construct(public readonly Cuenta $cuenta, string $mensaje)
    {
        parent::__construct($mensaje);
    }
}
