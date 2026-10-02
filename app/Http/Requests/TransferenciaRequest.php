<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidaCuentas;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Bolsa propia: el listado reabre el diálogo de la transferencia con sus
 * errores. El saldo de la cuenta origen lo revisa RegistradorMovimientos.
 */
class TransferenciaRequest extends FormRequest
{
    use ValidaCuentas;

    /**
     * @var string
     */
    protected $errorBag = 'transferencia';

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'cuenta_origen_id' => ['required', 'integer', $this->cuentaDelUsuario()],
            'cuenta_destino_id' => ['required', 'integer', 'different:cuenta_origen_id', $this->cuentaDelUsuario()],
            'monto' => ['required', 'numeric', 'gt:0', 'max:'.$this->montoMaximo(), 'decimal:0,2'],
            'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now(config('app.zona_negocio'))->toDateString()],
            'concepto' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $this->exigirCuentaActiva($validator, 'cuenta_origen_id');
                $this->exigirCuentaActiva($validator, 'cuenta_destino_id');
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cuenta_origen_id.exists' => 'Selecciona una de tus cuentas.',
            'cuenta_destino_id.exists' => 'Selecciona una de tus cuentas.',
            'cuenta_destino_id.different' => 'La cuenta destino debe ser distinta de la origen.',
            'monto.gt' => 'El monto debe ser mayor a 0.',
            'monto.max' => 'El monto es demasiado grande.',
            'monto.decimal' => 'El monto admite como máximo 2 decimales.',
            'fecha.before_or_equal' => 'La fecha no puede ser futura.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cuenta_origen_id' => 'cuenta origen',
            'cuenta_destino_id' => 'cuenta destino',
            'monto' => 'monto',
            'fecha' => 'fecha',
            'concepto' => 'concepto',
        ];
    }
}
