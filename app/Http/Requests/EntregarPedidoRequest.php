<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidaCuentas;
use App\Models\Pedido;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Cerrar un pedido al escanear su QR. Con saldo pendiente exige la cuenta a
 * la que entra el dinero; sin saldo la prohíbe, porque no entra nada. El
 * monto no viaja: lo calcula el servidor como el saldo exacto. Un pedido ya
 * entregado no exige nada: el controlador responde sin tocarlo (doble
 * escaneo).
 */
class EntregarPedidoRequest extends FormRequest
{
    use ValidaCuentas;

    public function authorize(): Response
    {
        return Gate::inspect('operar', $this->route('pedido'));
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        /** @var Pedido $pedido */
        $pedido = $this->route('pedido');

        if ($pedido->estaEntregado()) {
            return [];
        }

        return $pedido->tieneSaldo()
            ? ['cuenta_id' => ['required', 'integer', $this->cuentaDelUsuario()]]
            : ['cuenta_id' => ['prohibited']];
    }

    /**
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->exigirCuentaActiva($validator, 'cuenta_id'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cuenta_id.required' => 'Elige la cuenta a la que entra el cobro.',
            'cuenta_id.exists' => 'Selecciona una de tus cuentas.',
            'cuenta_id.prohibited' => 'El pedido no tiene saldo: no hay nada que cobrar.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['cuenta_id' => 'cuenta'];
    }
}
