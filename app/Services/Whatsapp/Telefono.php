<?php

namespace App\Services\Whatsapp;

/**
 * Única definición de cómo se comparan teléfonos (041, regla 76): los últimos
 * 10 dígitos. WhatsApp manda los de México como 521XXXXXXXXXX o 52XXXXXXXXXX y
 * el sistema los guarda de varias formas (+524491234567, 449 123 4567).
 */
final class Telefono
{
    public static function diezDigitos(?string $telefono): ?string
    {
        $digitos = substr((string) preg_replace('/\D/', '', (string) $telefono), -10);

        return strlen($digitos) === 10 ? $digitos : null;
    }

    /**
     * El wa_id con el que se le escribe a un teléfono mexicano de 10 dígitos.
     */
    public static function waIdMexico(string $diezDigitos): string
    {
        return '52'.$diezDigitos;
    }

    /**
     * "449 123 4567".
     */
    public static function legible(?string $telefono): string
    {
        $diez = self::diezDigitos($telefono);

        return $diez === null ? (string) $telefono : substr($diez, 0, 3).' '.substr($diez, 3, 3).' '.substr($diez, 6);
    }
}
