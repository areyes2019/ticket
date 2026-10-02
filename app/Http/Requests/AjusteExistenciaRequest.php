<?php

namespace App\Http\Requests;

use App\Enums\MotivoMovimientoInventario;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Ajuste manual o alta en existencias: la cantidad final que hay (no la
 * diferencia), con un motivo de la lista manual. Los motivos automáticos se
 * rechazan: no se falsifica el origen de un movimiento.
 */
class AjusteExistenciaRequest extends FormRequest
{
    /**
     * Bolsa propia: la ficha reabre el diálogo de ajuste con sus errores.
     *
     * @var string
     */
    protected $errorBag = 'ajuste';

    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('articulo'));
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'cantidad' => ['required', 'integer', 'min:0', 'max:'.CotizacionRequest::MAX_CANTIDAD],
            'motivo' => ['required', Rule::enum(MotivoMovimientoInventario::class)->only(MotivoMovimientoInventario::manuales())],
            'nota' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function cantidad(): int
    {
        return $this->integer('cantidad');
    }

    public function motivo(): MotivoMovimientoInventario
    {
        return MotivoMovimientoInventario::from($this->string('motivo')->toString());
    }

    public function nota(): ?string
    {
        $nota = $this->string('nota')->trim()->toString();

        return $nota === '' ? null : $nota;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.enum' => 'Elige uno de los motivos de la lista.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cantidad' => 'cantidad final',
            'motivo' => 'motivo',
            'nota' => 'nota',
        ];
    }
}
