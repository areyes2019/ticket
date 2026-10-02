<?php

namespace App\Http\Requests;

use App\Enums\RegimenFiscal;
use App\Enums\UsoCfdi;
use App\Rules\RfcValido;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Datos fiscales que captura el cliente en el portal público. No hay sesión
 * ni Policy: el token del enlace ya resolvió el pedido y es la autorización.
 */
class AutofacturaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'rfc' => $this->filled('rfc') ? $this->string('rfc')->replaceMatches('/\s+/', '')->upper()->toString() : null,
            'razon_social' => $this->filled('razon_social') ? $this->string('razon_social')->squish()->toString() : null,
            'correo' => $this->filled('correo') ? $this->string('correo')->trim()->toString() : null,
        ], fn (?string $valor) => $valor !== null));
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'rfc' => ['required', 'string', new RfcValido],
            'razon_social' => ['required', 'string', 'max:255'],
            'regimen_fiscal' => ['required', Rule::enum(RegimenFiscal::class)],
            'codigo_postal_fiscal' => ['required', 'string', 'regex:/^\d{5}$/'],
            'uso_cfdi' => ['required', Rule::in(array_keys(UsoCfdi::opcionesFactura()))],
            'correo' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * @return array{rfc: string, razon_social: string, regimen_fiscal: string, codigo_postal_fiscal: string, uso_cfdi: string, correo: string}
     */
    public function datos(): array
    {
        return $this->validated();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'regimen_fiscal.enum' => 'Selecciona un régimen fiscal del catálogo del SAT.',
            'uso_cfdi.in' => 'Selecciona un uso de CFDI de la lista.',
            'codigo_postal_fiscal.regex' => 'El código postal fiscal debe tener 5 dígitos.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'rfc' => 'RFC',
            'razon_social' => 'razón social',
            'regimen_fiscal' => 'régimen fiscal',
            'codigo_postal_fiscal' => 'código postal fiscal',
            'uso_cfdi' => 'uso de CFDI',
            'correo' => 'correo',
        ];
    }
}
