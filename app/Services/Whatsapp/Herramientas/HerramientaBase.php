<?php

namespace App\Services\Whatsapp\Herramientas;

use Illuminate\Support\Facades\Validator;

/**
 * Lo común: el esquema estricto y la revalidación con el validador de Laravel
 * (adición 5). Aunque Claude respete el esquema, la herramienta no confía.
 */
abstract class HerramientaBase implements Herramienta
{
    /**
     * @param  array<string, array<string, mixed>>  $propiedades
     * @return array<string, mixed>
     */
    protected static function objeto(array $propiedades): array
    {
        // Sin propiedades, el esquema es un objeto a secas (y la herramienta no
        // va en modo estricto: ver OrquestadorTurno::definicion()).
        if ($propiedades === []) {
            return ['type' => 'object'];
        }

        return [
            'type' => 'object',
            'properties' => $propiedades,
            'required' => array_keys($propiedades),
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @param  array<string, mixed>  $reglas
     * @return array<string, mixed>|ResultadoHerramienta los datos validados, o el error para el agente
     */
    protected function validar(array $entrada, array $reglas): array|ResultadoHerramienta
    {
        $validador = Validator::make($entrada, $reglas);

        if ($validador->fails()) {
            return ResultadoHerramienta::error('Entrada inválida: '.implode(' ', $validador->errors()->all()));
        }

        return $validador->validated();
    }
}
