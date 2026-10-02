<?php

namespace App\Enums;

enum TipoMovimiento: string
{
    case Ingreso = 'ingreso';
    case Egreso = 'egreso';
    case Transferencia = 'transferencia';
    case Ajuste = 'ajuste';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Ingreso => 'Ingreso',
            self::Egreso => 'Egreso',
            self::Transferencia => 'Transferencia',
            self::Ajuste => 'Ajuste',
        };
    }

    /**
     * Los que se capturan con el formulario de movimiento; la transferencia
     * tiene el suyo.
     *
     * @return list<self>
     */
    public static function manuales(): array
    {
        return [self::Ingreso, self::Egreso, self::Ajuste];
    }

    /**
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $tipo) => [$tipo->value => $tipo->etiqueta()])->all();
    }
}
