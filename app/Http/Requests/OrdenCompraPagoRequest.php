<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidaCuentas;
use App\Models\OrdenCompra;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Pago de contado de una orden de compra: cuenta y fecha. El monto es siempre
 * el total de la orden; si llega uno en la petición, se ignora. El saldo no
 * negativo lo revisa RegistradorMovimientos con la cuenta bloqueada.
 */
class OrdenCompraPagoRequest extends FormRequest
{
    use ValidaCuentas;

    /**
     * Bolsa propia: el detalle reabre el diálogo del pago con sus errores.
     *
     * @var string
     */
    protected $errorBag = 'pago';

    public function authorize(): Response
    {
        return Gate::inspect('operar', $this->route('ordenCompra'));
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'cuenta_id' => ['required', 'integer', $this->cuentaDelUsuario()],
            'fecha_pago' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now(config('app.zona_negocio'))->toDateString()],
        ];
    }

    /**
     * Cuenta activa y orden enviada. El controlador revisa el estado otra vez
     * con la fila bloqueada.
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $this->exigirCuentaActiva($validator, 'cuenta_id');

                /** @var OrdenCompra $orden */
                $orden = $this->route('ordenCompra');

                if (! $orden->puedeRegistrarPago()) {
                    $validator->errors()->add('cuenta_id', OrdenCompraPagoRequest::motivoNoPagable($orden));
                }
            },
        ];
    }

    public static function motivoNoPagable(OrdenCompra $orden): string
    {
        return $orden->estaPagada()
            ? 'La orden ya está pagada.'
            : 'Solo se paga una orden enviada: envíala al proveedor primero.';
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha_pago.before_or_equal' => 'La fecha de pago no puede ser futura.',
            'cuenta_id.exists' => 'Selecciona una de tus cuentas.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cuenta_id' => 'cuenta',
            'fecha_pago' => 'fecha de pago',
        ];
    }
}
