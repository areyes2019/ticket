<?php

namespace App\Enums;

/**
 * Lista fija de la orden de trabajo (022). "Otro" se acompaña del nombre que
 * escribe el usuario.
 */
enum ColorTinta: string
{
    case Negro = 'negro';
    case Azul = 'azul';
    case Rojo = 'rojo';
    case Verde = 'verde';
    case Morado = 'morado';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Negro => 'Negro',
            self::Azul => 'Azul',
            self::Rojo => 'Rojo',
            self::Verde => 'Verde',
            self::Morado => 'Morado',
            self::Otro => 'Otro',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $color) => [$color->value => $color->etiqueta()])->all();
    }
}
