<?php

namespace App\Http\Requests;

use App\Services\Etiquetas\MedidasPlanilla;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Las seis medidas de la planilla de etiquetas (031), en milímetros con un
 * decimal, y las columnas (corrección 2). Lo usan "Aplicar" y, extendido, el alta y edición de formatos.
 */
class MedidasPlanillaRequest extends FormRequest
{
    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            ...MedidasPlanilla::reglas(),
            'inicio' => ['nullable', 'integer', 'min:1'],
            'formato' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_map('mb_strtolower', MedidasPlanilla::ETIQUETAS);
    }

    public function medidas(): MedidasPlanilla
    {
        return MedidasPlanilla::desdeMilimetros($this->safe()->only([...array_keys(MedidasPlanilla::CAMPOS), 'columnas']));
    }
}
