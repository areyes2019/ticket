<?php

namespace App\Http\Requests;

use App\Enums\RegimenFiscal;
use App\Http\Requests\Concerns\NormalizaTelefono;
use App\Models\Cliente;
use App\Rules\RfcValido;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ClienteRequest extends FormRequest
{
    use NormalizaTelefono;

    /**
     * En la edición, ClientePolicy se revisa antes de validar para que un
     * cliente ajeno responda 404 aunque los datos sean inválidos.
     */
    public function authorize(): bool|Response
    {
        $cliente = $this->route('cliente');

        return $cliente === null ? true : Gate::inspect('update', $cliente);
    }

    /**
     * Normaliza teléfono y RFC antes de validar. El descuento en blanco o
     * ausente vale 0: sin descuento. La casilla de distribuidor sin marcar no
     * se envía: ausente o en blanco es "no es distribuidor".
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'telefono' => $this->filled('telefono') ? $this->normalizarTelefono($this->string('telefono')->toString()) : null,
            'rfc' => $this->filled('rfc') ? $this->string('rfc')->replaceMatches('/\s+/', '')->upper()->toString() : null,
        ], fn (?string $valor) => $valor !== null));

        $this->merge([
            'descuento_permanente' => $this->filled('descuento_permanente') ? $this->input('descuento_permanente') : '0',
            'es_distribuidor' => $this->filled('es_distribuidor') ? $this->input('es_distribuidor') : false,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            ...self::reglas($this->user()->id, $this->route('cliente')),
            // Solo en la ficha: la importación CSV no lo trae y el cliente queda sin marcar.
            'es_distribuidor' => ['required', 'boolean'],
        ];
    }

    /**
     * Reglas compartidas por el formulario y la importación CSV.
     *
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public static function reglas(int $usuarioId, ?Cliente $ignorar = null): array
    {
        return [
            'rfc' => [
                'required',
                'string',
                new RfcValido,
                Rule::unique('clientes', 'rfc')
                    ->where('user_id', $usuarioId)
                    ->withoutTrashed()
                    ->ignore($ignorar),
            ],
            'razon_social' => ['required', 'string', 'max:255'],
            'regimen_fiscal' => ['required', Rule::enum(RegimenFiscal::class)],
            'codigo_postal_fiscal' => ['required', 'string', 'regex:/^\d{5}$/'],
            'nombre_comercial' => ['nullable', 'string', 'max:255'],
            'nombre_contacto' => ['nullable', 'string', 'max:255'],
            'correo' => ['nullable', 'string', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'regex:/^\+52\d{10}$/'],
            'direccion_comercial' => ['nullable', 'string', 'max:255'],
            'descuento_permanente' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.Cliente::DESCUENTO_MAXIMO],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function mensajes(): array
    {
        return [
            'rfc.unique' => 'RFC duplicado: ya tienes un cliente registrado con ese RFC.',
            'regimen_fiscal.enum' => 'Selecciona un régimen fiscal del catálogo del SAT.',
            'codigo_postal_fiscal.regex' => 'El código postal fiscal debe tener 5 dígitos.',
            'telefono.regex' => 'El teléfono debe tener 10 dígitos.',
            'descuento_permanente.max' => 'El descuento permanente no puede pasar de '.Cliente::DESCUENTO_MAXIMO.'%.',
            'descuento_permanente.min' => 'El descuento permanente no puede ser negativo.',
            'descuento_permanente.decimal' => 'El descuento permanente admite hasta 2 decimales.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function atributos(): array
    {
        return [
            'rfc' => 'RFC',
            'razon_social' => 'razón social',
            'regimen_fiscal' => 'régimen fiscal',
            'codigo_postal_fiscal' => 'código postal fiscal',
            'nombre_comercial' => 'nombre comercial',
            'nombre_contacto' => 'nombre de contacto',
            'correo' => 'correo',
            'telefono' => 'teléfono',
            'direccion_comercial' => 'dirección comercial',
            'descuento_permanente' => 'descuento permanente',
            'es_distribuidor' => 'distribuidor',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::mensajes();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return self::atributos();
    }
}
