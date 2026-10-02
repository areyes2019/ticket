<?php

namespace App\Exceptions;

use App\Models\Cuenta;

class SaldoNegativoException extends OperacionTesoreriaRechazada
{
    public function __construct(Cuenta $cuenta)
    {
        parent::__construct($cuenta, "El movimiento dejaría la cuenta {$cuenta->nombre} con saldo negativo.");
    }
}
