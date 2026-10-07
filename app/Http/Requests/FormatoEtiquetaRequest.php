<?php

namespace App\Http\Requests;

use App\Models\FormatoEtiqueta;
use App\Services\Etiquetas\MedidasPlanilla;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de un formato de planilla (031): las medidas más el nombre,
 * único por usuario, y la casilla "Predeterminado".
 */
class FormatoEtiquetaRequest extends MedidasPlanillaRequest
{
    /**
     * En la edición, FormatoEtiquetaPolicy se revisa antes de validar para que
     * un formato ajeno responda 404 aunque los datos sean inválidos.
     */
    public function authorize(): bool|Response
    {
        $formato = $this->formato();

        return $formato === null ? true : Gate::inspect('update', $formato);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nombre' => trim((string) $this->input('nombre')),
            'es_predeterminado' => $this->boolean('es_predeterminado'),
        ]);
    }

    /**
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            ...MedidasPlanilla::reglas(),
            'nombre' => [
                'required', 'string', 'max:60',
                Rule::unique('formatos_etiqueta', 'nombre')
                    ->where('user_id', $this->user()->id)
                    ->ignore($this->formato()),
            ],
            'es_predeterminado' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [...parent::attributes(), 'nombre' => 'nombre del formato'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['nombre.unique' => 'Ya tienes un formato con ese nombre.'];
    }

    private function formato(): ?FormatoEtiqueta
    {
        return $this->route('formatoEtiqueta');
    }
}
