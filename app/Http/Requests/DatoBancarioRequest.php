<?php

namespace App\Http\Requests;

use App\Models\DatoBancario;
use App\Rules\ClabeValida;
use App\Rules\ImagenLegible;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Alta y edición de un dato bancario (027): las reglas son las mismas. Quién
 * puede lo decide la ruta (can:editar-emisor).
 */
class DatoBancarioRequest extends FormRequest
{
    private const NUMEROS = ['numero_cuenta', 'tarjeta', 'clabe'];

    /**
     * Pegar "4152 3133 1234 5678" desde la banca en línea funciona: se quitan
     * espacios y guiones y se guardan solo los dígitos. La casilla que no
     * llega está desmarcada.
     */
    protected function prepareForValidation(): void
    {
        $numeros = [];

        foreach (self::NUMEROS as $campo) {
            $limpio = preg_replace('/[\s-]+/u', '', (string) $this->input($campo, ''));
            $numeros[$campo] = $limpio === '' ? null : $limpio;
        }

        $this->merge([
            ...$numeros,
            'visible_en_cotizaciones' => $this->boolean('visible_en_cotizaciones'),
        ]);
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'nombre_banco' => ['required', 'string', 'max:100'],
            'beneficiario' => ['nullable', 'string', 'max:150'],
            'numero_cuenta' => ['nullable', 'digits_between:6,20'],
            'tarjeta' => ['nullable', 'digits_between:15,16'],
            'clabe' => ['nullable', 'digits:18', new ClabeValida],
            'visible_en_cotizaciones' => ['boolean'],
            'logo' => ['bail', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.DatoBancario::TAMANO_LOGO_KB, new ImagenLegible],
            'quitar_logo' => ['boolean'],
        ];
    }

    /**
     * Un banco sin ningún número no le sirve al cliente. El error no es de
     * ninguno de los tres campos en particular.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (collect(self::NUMEROS)->every(fn (string $campo) => $this->input($campo) === null)) {
                    $validator->errors()->add('numeros', 'Captura al menos un número de cuenta, tarjeta o CLABE.');
                }
            },
        ];
    }

    /**
     * Lo validado sin el logo, que no es columna.
     *
     * @return array<string, mixed>
     */
    public function datos(): array
    {
        return $this->safe()->except(['logo', 'quitar_logo']);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'numero_cuenta.digits_between' => 'El número de cuenta debe tener entre 6 y 20 dígitos.',
            'tarjeta.digits_between' => 'La tarjeta debe tener 15 o 16 dígitos.',
            'clabe.digits' => 'La CLABE debe tener exactamente 18 dígitos.',
            'logo.mimes' => 'El logo debe ser JPG, PNG o WEBP.',
            'logo.max' => 'El logo pesa más de 2 MB. Elige uno más ligero.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre_banco' => 'nombre del banco',
            'beneficiario' => 'beneficiario',
            'numero_cuenta' => 'número de cuenta',
            'tarjeta' => 'tarjeta',
            'clabe' => 'CLABE',
            'logo' => 'imagen del logo',
        ];
    }
}
