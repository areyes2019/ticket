<?php

namespace App\Enums;

enum ResultadoAcceso: string
{
    case Exitoso = 'exitoso';
    case Fallido = 'fallido';
    case Suspendido = 'suspendido';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Exitoso => 'Exitoso',
            self::Fallido => 'Fallido',
            self::Suspendido => 'Bloqueado por suspensión',
        };
    }
}
