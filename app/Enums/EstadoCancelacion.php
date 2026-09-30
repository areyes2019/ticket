<?php

namespace App\Enums;

/**
 * cancellation_status de facturapi.io. none es lo que trae toda factura antes
 * de intentar cancelarla; rejected (el receptor rechazó) no se ha visto en la
 * API real y se contempla para no romper.
 */
enum EstadoCancelacion: string
{
    case Ninguna = 'none';
    case Pendiente = 'pending';
    case Verificando = 'verifying';
    case Aceptada = 'accepted';
    case Rechazada = 'rejected';

    public function enCurso(): bool
    {
        return $this === self::Pendiente || $this === self::Verificando;
    }
}
