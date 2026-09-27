<?php

namespace App\Enums;

enum TasaIva: string
{
    case Dieciseis = '16';
    case Cero = '0';
    case Exento = 'exento';

    public function factor(): float
    {
        return $this === self::Dieciseis ? 0.16 : 0.0;
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Dieciseis => '16%',
            self::Cero => '0%',
            self::Exento => 'Exento',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        $opciones = [];

        foreach (self::cases() as $tasa) {
            $opciones[$tasa->value] = $tasa->etiqueta();
        }

        return $opciones;
    }
}
