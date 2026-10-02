<?php

namespace App\Rules;

use App\Services\Imagenes\GuardadorImagenWebp;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

class ImagenLegible implements ValidationRule
{
    /**
     * El archivo es un JPEG, PNG o WEBP que GD puede decodificar, comprobado
     * por su contenido; así una imagen dañada se rechaza antes de guardar nada.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! app(GuardadorImagenWebp::class)->esImagen((string) file_get_contents($value->getRealPath()))) {
            $fail('La :attribute no es una imagen JPG, PNG ni WEBP legible.');
        }
    }
}
