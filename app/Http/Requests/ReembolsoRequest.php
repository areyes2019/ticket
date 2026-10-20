<?php

namespace App\Http\Requests;

use App\Enums\DecisionFacturaReembolso;
use App\Enums\EstadoFactura;
use App\Enums\MotivoCancelacion;
use App\Models\Cotizacion;
use App\Models\Pedido;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Reembolso de una venta o de una cotización sin venta (038). La pregunta de
 * la factura solo se valida cuando hay una timbrada sin cancelación en curso;
 * si no, se ignora.
 */
class ReembolsoRequest extends FormRequest
{
    /**
     * Bolsa propia: el detalle reabre la ventana del reembolso con sus errores.
     *
     * @var string
     */
    protected $errorBag = 'reembolso';

    public function authorize(): Response
    {
        return Gate::inspect('reembolsar', $this->documento());
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        $pregunta = $this->preguntaPorFactura();

        return [
            'motivo' => ['required', 'string', 'max:500'],
            'cancelar_factura' => $pregunta ? ['required', Rule::in(['si', 'no'])] : ['nullable'],
            'motivo_cancelacion' => $pregunta && $this->input('cancelar_factura') === 'si'
                ? ['required', Rule::in(array_map(fn (MotivoCancelacion $motivo) => $motivo->value, MotivoCancelacion::paraReembolso()))]
                : ['nullable'],
        ];
    }

    public function documento(): Pedido|Cotizacion
    {
        return $this->route('pedido') ?? $this->route('cotizacion');
    }

    /**
     * Factura timbrada sin cancelación en curso: hay que decidir.
     */
    public function preguntaPorFactura(): bool
    {
        $factura = $this->documento()->facturaParaReembolso();

        return $factura !== null && $factura->estado === EstadoFactura::Timbrada && ! $factura->cancelacionEnCurso();
    }

    public function decisionFactura(): ?DecisionFacturaReembolso
    {
        if (! $this->preguntaPorFactura()) {
            return null;
        }

        return $this->validated('cancelar_factura') === 'si' ? DecisionFacturaReembolso::Cancelar : DecisionFacturaReembolso::Conservar;
    }

    public function motivoCancelacion(): ?MotivoCancelacion
    {
        return $this->decisionFactura() === DecisionFacturaReembolso::Cancelar
            ? MotivoCancelacion::from($this->validated('motivo_cancelacion'))
            : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cancelar_factura.required' => 'Elige si también se cancela la factura.',
            'motivo_cancelacion.required' => 'Elige el motivo de cancelación del SAT.',
            'motivo_cancelacion.in' => 'Elige un motivo 02, 03 o 04. El 01 se usa desde Facturación.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'motivo' => 'motivo del reembolso',
            'cancelar_factura' => 'cancelar factura',
            'motivo_cancelacion' => 'motivo de cancelación',
        ];
    }
}
