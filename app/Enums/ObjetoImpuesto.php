<?php

namespace App\Enums;

/**
 * Catálogo SAT c_ObjetoImp (CFDI 4.0).
 */
enum ObjetoImpuesto: string
{
    case NoObjeto = '01';
    case SiObjeto = '02';
    case SiObjetoNoObligadoDesglose = '03';
    case SiObjetoNoCausa = '04';

    public function descripcion(): string
    {
        return match ($this) {
            self::NoObjeto => 'No objeto de impuesto',
            self::SiObjeto => 'Sí objeto de impuesto',
            self::SiObjetoNoObligadoDesglose => 'Sí objeto del impuesto y no obligado al desglose',
            self::SiObjetoNoCausa => 'Sí objeto del impuesto y no causa impuesto',
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

        foreach (self::cases() as $objeto) {
            $opciones[$objeto->value] = $objeto->value.' – '.$objeto->descripcion();
        }

        return $opciones;
    }
}
