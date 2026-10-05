<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ClabeValida implements ValidationRule
{
    private const PESOS = [3, 7, 1];

    /**
     * El dígito 18 de la CLABE se calcula con los 17 anteriores: un dedo
     * chueco al teclear se detecta antes de que salga impresa. La longitud la
     * revisa digits:18; aquí solo se opina sobre 18 dígitos.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^\d{18}$/', $value) !== 1) {
            return;
        }

        if (self::digitoVerificador(substr($value, 0, 17)) !== (int) $value[17]) {
            $fail('La CLABE no es válida: revisa que esté bien escrita.');
        }
    }

    /**
     * Pesos 3, 7, 1 cíclicos; de cada producto solo cuenta su último dígito.
     */
    public static function digitoVerificador(string $diecisiete): int
    {
        $suma = 0;

        foreach (str_split($diecisiete) as $posicion => $digito) {
            $suma += ((int) $digito * self::PESOS[$posicion % 3]) % 10;
        }

        return (10 - $suma % 10) % 10;
    }
}
