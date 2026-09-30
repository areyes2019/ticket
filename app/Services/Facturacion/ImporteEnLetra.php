<?php

namespace App\Services\Facturacion;

/**
 * Importe en pesos con letra, como se imprime en un CFDI:
 * "MIL CIENTO SESENTA PESOS 50/100 M.N.".
 */
final class ImporteEnLetra
{
    private const UNIDADES = ['', 'UN', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
        'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE',
        'VEINTE', 'VEINTIÚN', 'VEINTIDÓS', 'VEINTITRÉS', 'VEINTICUATRO', 'VEINTICINCO', 'VEINTISÉIS', 'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE'];

    private const DECENAS = ['', '', '', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];

    private const CENTENAS = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

    public static function convertir(float|string $importe): string
    {
        $centavosTotales = (int) round(round((float) $importe, 6) * 100);
        $pesos = intdiv($centavosTotales, 100);
        $centavos = $centavosTotales % 100;

        $letras = $pesos === 0 ? 'CERO' : self::numero($pesos);
        $moneda = $pesos === 1 ? 'PESO' : 'PESOS';

        // "UN MILLÓN DE PESOS": los millones exactos llevan "DE".
        if ($pesos >= 1000000 && $pesos % 1000000 === 0) {
            $moneda = 'DE '.$moneda;
        }

        return $letras.' '.$moneda.' '.str_pad((string) $centavos, 2, '0', STR_PAD_LEFT).'/100 M.N.';
    }

    private static function numero(int $numero): string
    {
        $millones = intdiv($numero, 1000000);
        $miles = intdiv($numero % 1000000, 1000);
        $resto = $numero % 1000;
        $partes = [];

        if ($millones > 0) {
            $partes[] = $millones === 1 ? 'UN MILLÓN' : self::numero($millones).' MILLONES';
        }

        if ($miles > 0) {
            $partes[] = $miles === 1 ? 'MIL' : self::centenas($miles).' MIL';
        }

        if ($resto > 0) {
            $partes[] = self::centenas($resto);
        }

        return implode(' ', $partes);
    }

    private static function centenas(int $numero): string
    {
        if ($numero === 100) {
            return 'CIEN';
        }

        $centena = intdiv($numero, 100);
        $decenas = $numero % 100;

        return trim(self::CENTENAS[$centena].' '.self::decenas($decenas));
    }

    private static function decenas(int $numero): string
    {
        if ($numero < 30) {
            return self::UNIDADES[$numero];
        }

        $unidad = $numero % 10;

        return self::DECENAS[intdiv($numero, 10)].($unidad > 0 ? ' Y '.self::UNIDADES[$unidad] : '');
    }
}
