<?php

namespace App\Enums;

enum EstadoComplementoPago: string
{
    case Pendiente = 'pendiente';
    case Timbrado = 'timbrado';
    case Error = 'error';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Timbrado => 'Timbrado',
            self::Error => 'Error al timbrar',
        };
    }
}
