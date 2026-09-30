<?php

namespace App\Enums;

/**
 * Catálogo SAT c_MotivoCancelacion.
 */
enum MotivoCancelacion: string
{
    case ErroresConRelacion = '01';
    case ErroresSinRelacion = '02';
    case NoSeLlevoACabo = '03';
    case FacturaGlobal = '04';

    public function descripcion(): string
    {
        return match ($this) {
            self::ErroresConRelacion => 'Comprobante emitido con errores con relación',
            self::ErroresSinRelacion => 'Comprobante emitido con errores sin relación',
            self::NoSeLlevoACabo => 'No se llevó a cabo la operación',
            self::FacturaGlobal => 'Operación nominativa relacionada en una factura global',
        };
    }

    /**
     * Solo "con relación" exige la factura que sustituye a la cancelada.
     */
    public function requiereSustituta(): bool
    {
        return $this === self::ErroresConRelacion;
    }

    /**
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        $opciones = [];

        foreach (self::cases() as $motivo) {
            $opciones[$motivo->value] = $motivo->value.' – '.$motivo->descripcion();
        }

        return $opciones;
    }
}
