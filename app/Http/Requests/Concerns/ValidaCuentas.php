<?php

namespace App\Http\Requests\Concerns;

use App\Models\Cuenta;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;

/**
 * Reglas de "cuenta del usuario y activa" para los formularios que mueven
 * dinero (movimientos, transferencias y pagos de cotización). El servicio lo
 * vuelve a revisar con la cuenta bloqueada.
 */
trait ValidaCuentas
{
    protected function cuentaDelUsuario(): Exists
    {
        return Rule::exists('cuentas', 'id')->where('user_id', $this->user()->id);
    }

    /**
     * Agrega el error de cuenta inactiva al campo, si la cuenta pasó las demás
     * reglas.
     */
    protected function exigirCuentaActiva(Validator $validator, string $campo, ?int $permitida = null): void
    {
        if ($validator->errors()->has($campo)) {
            return;
        }

        $cuenta = Cuenta::find($this->input($campo));

        if ($cuenta !== null && ! $cuenta->activa && $cuenta->id !== $permitida) {
            $validator->errors()->add($campo, "La cuenta {$cuenta->nombre} está inactiva y no admite movimientos.");
        }
    }

    /**
     * El monto tiene como máximo 10 dígitos enteros (decimal(14,2)).
     */
    protected function montoMaximo(): string
    {
        return '9999999999.99';
    }
}
