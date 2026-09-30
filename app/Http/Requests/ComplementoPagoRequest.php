<?php

namespace App\Http\Requests;

use App\Enums\FormaPago;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ComplementoPagoRequest extends FormRequest
{
    /**
     * Bolsa propia: el detalle reabre el diálogo del complemento con sus errores.
     *
     * @var string
     */
    protected $errorBag = 'complemento';

    public function authorize(): Response
    {
        return Gate::inspect('operar', $this->route('factura'));
    }

    /**
     * El monto no puede pasar del total: el SAT rechaza un pago mayor al saldo
     * anterior, que aquí es el total de la factura.
     *
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        $hoy = now(config('app.zona_negocio'))->toDateString();

        return [
            'fecha_pago' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$hoy],
            'monto' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:'.$this->route('factura')->total],
            'forma_pago' => ['required', Rule::enum(FormaPago::class)->except([FormaPago::PorDefinir])],
        ];
    }

    /**
     * @return array{fecha_pago: string, monto: string, forma_pago: string}
     */
    public function datos(): array
    {
        return $this->safe()->only(['fecha_pago', 'monto', 'forma_pago']);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha_pago.before_or_equal' => 'La fecha de pago no puede ser futura.',
            'monto.max' => 'El monto no puede ser mayor al total de la factura ($'.number_format((float) $this->route('factura')->total, 2).').',
            'forma_pago.enum' => 'Elige la forma en que se recibió el pago (no puede ser 99 – Por definir).',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'fecha_pago' => 'fecha de pago',
            'forma_pago' => 'forma de pago',
        ];
    }
}
