<?php

namespace App\Http\Requests;

use App\Models\RespuestaRapida;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de una respuesta rápida (043, reglas 2 y 3). Cualquier
 * usuario con sesión puede (regla 19).
 */
class RespuestaRapidaRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['atajo' => RespuestaRapida::normalizarAtajo((string) $this->input('atajo', ''))]);
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'atajo' => [
                'bail', 'required', 'string', 'max:'.RespuestaRapida::MAX_ATAJO,
                'not_regex:/\s/',
                'regex:/^[a-z0-9_-]+$/',
                Rule::unique('respuestas_rapidas', 'atajo')->ignore($this->route('respuestaRapida')),
            ],
            'texto' => ['required', 'string', 'max:'.RespuestaRapida::MAX_TEXTO],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'atajo.not_regex' => 'El atajo debe ser una sola palabra, sin espacios.',
            'atajo.regex' => 'El atajo solo puede tener letras, números, - y _.',
            'atajo.unique' => 'Ya existe una respuesta con el atajo /'.$this->input('atajo').'.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['atajo' => 'atajo', 'texto' => 'texto'];
    }
}
