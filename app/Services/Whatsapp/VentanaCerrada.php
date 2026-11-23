<?php

namespace App\Services\Whatsapp;

use RuntimeException;

/**
 * Pasaron más de 24 horas desde el último mensaje del cliente: solo se puede
 * mandar una plantilla (adición 2).
 */
class VentanaCerrada extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Pasaron más de 24 horas desde el último mensaje del cliente. Solo puedes mandar una plantilla.');
    }
}
