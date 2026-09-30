<?php

namespace App\Enums;

/**
 * Catálogo SAT c_MetodoPago (CFDI 4.0): solo dos valores.
 */
enum MetodoPago: string
{
    case UnaExhibicion = 'PUE';
    case Diferido = 'PPD';

    public function etiqueta(): string
    {
        return match ($this) {
            self::UnaExhibicion => 'Pago en una sola exhibición',
            self::Diferido => 'Pago en parcialidades o diferido',
        };
    }

    /**
     * Opciones para un select: clave => "clave – descripción".
     *
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        $opciones = [];

        foreach (self::cases() as $metodo) {
            $opciones[$metodo->value] = $metodo->value.' – '.$metodo->etiqueta();
        }

        return $opciones;
    }
}
