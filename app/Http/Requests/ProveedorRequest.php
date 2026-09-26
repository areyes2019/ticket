<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaTelefono;
use App\Rules\RfcValido;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProveedorRequest extends FormRequest
{
    use NormalizaTelefono;

    /**
     * En la edición, ProveedorPolicy se revisa antes de validar para que un
     * proveedor ajeno responda 404 aunque los datos sean inválidos.
     */
    public function authorize(): bool|Response
    {
        $proveedor = $this->route('proveedor');

        return $proveedor === null ? true : Gate::inspect('update', $proveedor);
    }

    /**
     * Normaliza teléfono y RFC antes de validar.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'telefono' => $this->filled('telefono') ? $this->normalizarTelefono($this->string('telefono')->toString()) : null,
            'rfc' => $this->filled('rfc') ? $this->string('rfc')->replaceMatches('/\s+/', '')->upper()->toString() : null,
        ], fn (?string $valor) => $valor !== null));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'nombre_comercial' => ['required', 'string', 'max:255'],
            'nombre_contacto' => ['nullable', 'string', 'max:255'],
            'correo' => ['nullable', 'string', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'regex:/^\+52\d{10}$/'],
            'rfc' => [
                'nullable',
                'string',
                new RfcValido,
                Rule::unique('proveedores', 'rfc')
                    ->where('user_id', $this->user()->id)
                    ->withoutTrashed()
                    ->ignore($this->route('proveedor')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'telefono.regex' => 'El teléfono debe tener 10 dígitos.',
            'rfc.unique' => 'RFC duplicado: ya tienes un proveedor registrado con ese RFC.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre_comercial' => 'nombre comercial',
            'nombre_contacto' => 'nombre de contacto',
            'correo' => 'correo',
            'telefono' => 'teléfono',
            'rfc' => 'RFC',
        ];
    }
}
