<?php

namespace App\Http\Requests;

use App\Enums\ClaveConfiguracion;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Las reglas de cada clave las declara ClaveConfiguracion: dependen de la
 * clave, no del endpoint.
 */
class ConfiguracionRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return collect(ClaveConfiguracion::cases())
            ->mapWithKeys(fn (ClaveConfiguracion $clave) => [$clave->value => $clave->reglas()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return collect(ClaveConfiguracion::cases())
            ->mapWithKeys(fn (ClaveConfiguracion $clave) => [$clave->value => mb_strtolower($clave->etiqueta())])
            ->all();
    }
}
