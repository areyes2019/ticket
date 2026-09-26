<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AnalizarConstanciaRequest extends FormRequest
{
    /**
     * La ruta ya exige sesión; el endpoint no lee ni modifica ningún cliente.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * El tipo de archivo se valida por su contenido real (mimes), no por la
     * extensión: un .exe renombrado a .png se rechaza.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'archivo' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'qr_url' => ['nullable', 'string', 'url', 'max:2048'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasFile('archivo') && ! $this->filled('qr_url')) {
                    $validator->errors()->add('archivo', 'Sube la Constancia de Situación Fiscal.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archivo.mimes' => 'La constancia debe ser un PDF, JPG o PNG.',
            'archivo.max' => 'La constancia no debe pesar más de 10 MB.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'archivo' => 'constancia',
            'qr_url' => 'dirección del código QR',
        ];
    }
}
