<?php

namespace App\Enums;

enum TipoDescuento: string
{
    case Porcentaje = 'porcentaje';
    case Monto = 'monto';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Porcentaje => '%',
            self::Monto => '$',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        return [self::Porcentaje->value => '%', self::Monto->value => '$'];
    }
}
