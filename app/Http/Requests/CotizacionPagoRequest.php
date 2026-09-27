<?php

namespace App\Http\Requests;

use App\Enums\FormaPago;
use App\Enums\TipoPago;
use App\Models\Cotizacion;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CotizacionPagoRequest extends FormRequest
{
    /**
     * Bolsa propia: el detalle reabre el diálogo del pago con sus errores.
     *
     * @var string
     */
    protected $errorBag = 'pago';

    public function authorize(): Response
    {
        return Gate::inspect('operar', $this->route('cotizacion'));
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::enum(TipoPago::class)],
            'fecha_pago' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now(config('app.zona_negocio'))->toDateString()],
            'forma_pago' => ['required', Rule::enum(FormaPago::class)],
            'monto' => ['exclude_unless:tipo,anticipo', 'required', 'numeric', 'gt:0', 'decimal:0,2'],
        ];
    }

    /**
     * Reglas de negocio del pago (un solo anticipo, sin sobrepago, solo en
     * enviada). El controlador las vuelve a revisar con la fila bloqueada.
     *
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Cotizacion $cotizacion */
                $cotizacion = $this->route('cotizacion');
                $motivo = $cotizacion->motivoRechazoPago(TipoPago::from($this->input('tipo')), $this->input('monto'));

                if ($motivo !== null) {
                    $validator->errors()->add('monto', $motivo);
                }
            },
        ];
    }

    public function tipo(): TipoPago
    {
        return TipoPago::from($this->validated('tipo'));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha_pago.before_or_equal' => 'La fecha de pago no puede ser futura.',
            'monto.gt' => 'El monto debe ser mayor a 0.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tipo' => 'tipo de pago',
            'fecha_pago' => 'fecha de pago',
            'forma_pago' => 'forma de pago',
            'monto' => 'monto',
        ];
    }
}
