<?php

namespace App\Http\Requests;

use App\Enums\EstadoFactura;
use App\Enums\MotivoCancelacion;
use App\Models\Factura;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CancelarFacturaRequest extends FormRequest
{
    /**
     * Bolsa propia: el detalle reabre el diálogo de cancelación con sus errores.
     *
     * @var string
     */
    protected $errorBag = 'cancelacion';

    public function authorize(): Response
    {
        return Gate::inspect('operar', $this->route('factura'));
    }

    /**
     * La sustituta (solo con motivo 01) es otra factura propia y timbrada.
     *
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        $factura = $this->route('factura');

        return [
            'motivo_cancelacion' => ['required', Rule::enum(MotivoCancelacion::class)],
            'factura_sustituta_id' => [
                Rule::requiredIf($this->input('motivo_cancelacion') === MotivoCancelacion::ErroresConRelacion->value),
                'nullable',
                'integer',
                Rule::exists('facturas', 'id')
                    ->where('user_id', $this->user()->id)
                    ->where('estado', EstadoFactura::Timbrada->value)
                    ->whereNot('id', $factura->id),
            ],
        ];
    }

    public function motivo(): MotivoCancelacion
    {
        return MotivoCancelacion::from($this->validated('motivo_cancelacion'));
    }

    public function sustituta(): ?Factura
    {
        $id = $this->validated('factura_sustituta_id');

        return $this->motivo()->requiereSustituta() && $id !== null ? Factura::find($id) : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'factura_sustituta_id.required' => 'Con el motivo 01 elige la factura que sustituye a esta.',
            'factura_sustituta_id.exists' => 'La factura sustituta debe ser otra de tus facturas timbradas.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'motivo_cancelacion' => 'motivo de cancelación',
            'factura_sustituta_id' => 'factura sustituta',
        ];
    }
}
