<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidaCuentas;
use App\Models\Pedido;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Un pago de pedido: el monto que se recibió, sin porcentaje mínimo, hasta
 * el saldo pendiente. El controlador lo vuelve a revisar con la fila
 * bloqueada.
 */
class PedidoPagoRequest extends FormRequest
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
        return Gate::inspect('operar', $this->route('pedido'));
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'fecha_pago' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now(config('app.zona_negocio'))->toDateString()],
            'cuenta_id' => ['required', 'integer', $this->cuentaDelUsuario()],
            'monto' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:'.$this->montoMaximo()],
        ];
    }

    /**
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $this->exigirCuentaActiva($validator, 'cuenta_id');

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Pedido $pedido */
                $pedido = $this->route('pedido');
                $motivo = self::motivoRechazo($pedido, $this->input('monto'));

                if ($motivo !== null) {
                    $validator->errors()->add('monto', $motivo);
                }
            },
        ];
    }

    /**
     * Por qué no se puede registrar ese monto; null si se puede. También lo usa
     * el controlador, con la fila bloqueada.
     */
    public static function motivoRechazo(Pedido $pedido, float|string|null $monto): ?string
    {
        if ($pedido->cobraEnCotizacion()) {
            return "Los pagos se registran en la cotización {$pedido->cotizacion->folio_formateado}.";
        }

        if (! $pedido->puedeRegistrarPago()) {
            return 'El pedido ya está pagado por completo.';
        }

        if (CalculadoraTotalesDocumento::centavos($monto) > CalculadoraTotalesDocumento::centavos($pedido->saldoPendiente())) {
            return 'El pago no puede ser mayor al saldo pendiente ($'.number_format((float) $pedido->saldoPendiente(), 2).').';
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha_pago.before_or_equal' => 'La fecha de pago no puede ser futura.',
            'monto.gt' => 'El monto debe ser mayor a 0.',
            'cuenta_id.exists' => 'Selecciona una de tus cuentas.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'fecha_pago' => 'fecha de pago',
            'cuenta_id' => 'cuenta',
            'monto' => 'monto',
        ];
    }
}
