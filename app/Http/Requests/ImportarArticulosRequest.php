<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ImportarArticulosRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'catalogo_id' => ['required', 'integer', ArticuloRequest::reglaCatalogo($this->user()->id)],
            'archivo' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'catalogo_id.exists' => 'Selecciona uno de tus catálogos.',
            'archivo.mimes' => 'El archivo debe ser un CSV.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'catalogo_id' => 'catálogo',
            'archivo' => 'archivo',
        ];
    }
}
