<?php

namespace App\Services\Whatsapp\Herramientas;

use App\Services\Whatsapp\ContextoTurno;

/**
 * Una "ventanilla" de un agente (041, adición 5). Recibe el turno, con la
 * conversación, la cuenta y el teléfono: nunca un user_id ni un teléfono que
 * mande el agente. Sus candados viven aquí, no en las instrucciones.
 */
interface Herramienta
{
    public function nombre(): string;

    public function descripcion(): string;

    /**
     * JSON Schema de la entrada, estricto: todo requerido y sin campos extra
     * (lo opcional es nullable).
     *
     * @return array<string, mixed>
     */
    public function esquema(): array;

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function ejecutar(ContextoTurno $turno, array $entrada): ResultadoHerramienta;
}
