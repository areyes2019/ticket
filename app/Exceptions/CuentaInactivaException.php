<?php

namespace App\Exceptions;

use App\Models\Cuenta;

class CuentaInactivaException extends OperacionTesoreriaRechazada
{
    public function __construct(Cuenta $cuenta)
    {
        parent::__construct($cuenta, "La cuenta {$cuenta->nombre} está inactiva y no admite movimientos.");
    }
}
