<?php

namespace App\Enums;

enum TipoCuenta: string
{
    case Efectivo = 'efectivo';
    case Banco = 'banco';
    case Digital = 'digital';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Efectivo => 'Efectivo',
            self::Banco => 'Banco',
            self::Digital => 'Digital',
            self::Otro => 'Otro',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $tipo) => [$tipo->value => $tipo->etiqueta()])->all();
    }
}
