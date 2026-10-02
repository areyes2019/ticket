<?php

namespace App\Http\Requests;

use App\Enums\TipoCuenta;
use App\Models\Cuenta;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CuentaRequest extends FormRequest
{
    /**
     * En la edición, CuentaPolicy se revisa antes de validar para que una
     * cuenta ajena responda 404 aunque los datos sean inválidos.
     */
    public function authorize(): bool|Response
    {
        $cuenta = $this->cuenta();

        return $cuenta === null ? true : Gate::inspect('update', $cuenta);
    }

    /**
     * En la edición "activa" es una casilla: si no llega, está desmarcada.
     */
    protected function prepareForValidation(): void
    {
        if ($this->cuenta() !== null) {
            $this->merge(['activa' => $this->boolean('activa')]);
        }
    }

    /**
     * El saldo inicial solo se acepta en el alta: en la edición no hay regla,
     * así que no llega a validated() y no cambia.
     *
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        $reglas = [
            'nombre' => ['required', 'string', 'max:100'],
            'tipo' => ['required', Rule::enum(TipoCuenta::class)],
            'activa' => ['sometimes', 'boolean'],
        ];

        if ($this->cuenta() === null) {
            $reglas['saldo_inicial'] = ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'];
        }

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'saldo_inicial.min' => 'El saldo inicial no puede ser negativo.',
            'saldo_inicial.decimal' => 'El saldo inicial admite como máximo 2 decimales.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nombre' => 'nombre',
            'tipo' => 'tipo',
            'saldo_inicial' => 'saldo inicial',
        ];
    }

    private function cuenta(): ?Cuenta
    {
        return $this->route('cuenta');
    }
}
