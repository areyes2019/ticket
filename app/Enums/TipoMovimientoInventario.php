<?php

namespace App\Enums;

/**
 * Nombre completo para no chocar con TipoMovimiento (Tesorería).
 */
enum TipoMovimientoInventario: string
{
    case Entrada = 'entrada';
    case Salida = 'salida';
    case Ajuste = 'ajuste';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Entrada => 'Entrada',
            self::Salida => 'Salida',
            self::Ajuste => 'Ajuste',
        };
    }
}
