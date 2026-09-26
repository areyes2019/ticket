<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use PhpCfdi\Rfc\Rfc;

class RfcValido implements ValidationRule
{
    /**
     * Valida la estructura del RFC (persona física, moral o genérico); no consulta al SAT.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || Rfc::parseOrNull($value) === null) {
            $fail('El :attribute no tiene un formato válido.');
        }
    }
}
