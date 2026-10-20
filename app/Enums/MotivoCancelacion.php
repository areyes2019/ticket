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
     * Los que ofrece el reembolso de una venta (038): sin el 01, que exige una
     * factura sustituta y no aplica cuando se devuelve el dinero.
     *
     * @return list<self>
     */
    public static function paraReembolso(): array
    {
        return [self::ErroresSinRelacion, self::NoSeLlevoACabo, self::FacturaGlobal];
    }

    /**
     * @return array<string, string>
     */
    public static function opcionesParaReembolso(): array
    {
        return collect(self::paraReembolso())->mapWithKeys(fn (self $motivo) => [$motivo->value => $motivo->value.' – '.$motivo->descripcion()])->all();
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
