<?php

namespace App\Http\Requests;

use App\Enums\RegimenFiscal;
use App\Http\Requests\Concerns\NormalizaTelefono;
use App\Rules\RfcValido;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Datos del emisor (026). El formulario se guarda completo o no se guarda; las
 * columnas nullable son para la fila, no para el formulario.
 */
class ActualizarEmisorRequest extends FormRequest
{
    use NormalizaTelefono;

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'telefono' => $this->filled('telefono') ? $this->normalizarTelefono($this->string('telefono')->toString()) : null,
            'whatsapp' => $this->filled('whatsapp') ? $this->normalizarTelefono($this->string('whatsapp')->toString()) : null,
            'rfc' => $this->filled('rfc') ? $this->string('rfc')->replaceMatches('/\s+/', '')->upper()->toString() : null,
        ], fn (?string $valor) => $valor !== null));
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'rfc' => ['required', 'string', new RfcValido],
            'regimen_fiscal' => ['required', Rule::enum(RegimenFiscal::class)],
            'domicilio' => ['nullable', 'string', 'max:255'],
            'correo' => ['nullable', 'string', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'regex:/^\+52\d{10}$/'],
            'sitio_web' => ['nullable', 'string', 'max:255'],
            'whatsapp' => ['nullable', 'string', 'regex:/^\+52\d{10}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'regimen_fiscal.enum' => 'Selecciona un régimen fiscal del catálogo del SAT.',
            'telefono.regex' => 'El teléfono debe tener 10 dígitos.',
            'whatsapp.regex' => 'El WhatsApp debe tener 10 dígitos.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre' => 'nombre o razón social',
            'rfc' => 'RFC',
            'regimen_fiscal' => 'régimen fiscal',
            'domicilio' => 'domicilio',
            'correo' => 'correo',
            'telefono' => 'teléfono',
            'sitio_web' => 'sitio web',
            'whatsapp' => 'WhatsApp',
        ];
    }
}
