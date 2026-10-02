<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Umbrales de reposición. Un mínimo en 0 significa "no me avises"; sin
 * máximo, el techo de la sugerencia es el propio mínimo.
 */
class ParametrosExistenciaRequest extends FormRequest
{
    /**
     * Bolsa propia: la ficha reabre el diálogo de umbrales con sus errores.
     *
     * @var string
     */
    protected $errorBag = 'parametros';

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
            'minimo' => ['required', 'integer', 'min:0', 'max:'.CotizacionRequest::MAX_CANTIDAD],
            'maximo' => ['nullable', 'integer', 'min:0', 'max:'.CotizacionRequest::MAX_CANTIDAD, 'gte:minimo'],
        ];
    }

    /**
     * @return array{minimo: int, maximo: int|null}
     */
    public function parametros(): array
    {
        return [
            'minimo' => $this->integer('minimo'),
            'maximo' => $this->filled('maximo') ? $this->integer('maximo') : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'maximo.gte' => 'El máximo no puede ser menor que el mínimo.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'minimo' => 'mínimo',
            'maximo' => 'máximo',
        ];
    }
}
