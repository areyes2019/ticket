<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * El cliente de la copia: uno propio y activo (puede ser el mismo).
 */
class DuplicarCotizacionRequest extends FormRequest
{
    /**
     * Bolsa propia: el detalle reabre el diálogo de duplicar con sus errores.
     *
     * @var string
     */
    protected $errorBag = 'duplicar';

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
            'cliente_id' => ['required', 'integer', Rule::exists('clientes', 'id')->where('user_id', $this->user()->id)->withoutTrashed()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cliente_id.required' => 'Elige el cliente de la copia.',
            'cliente_id.exists' => 'Elige el cliente de la copia.',
        ];
    }
}
