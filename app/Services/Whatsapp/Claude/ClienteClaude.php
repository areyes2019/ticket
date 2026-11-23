<?php

namespace App\Services\Whatsapp\Claude;

/**
 * Una llamada a la Messages API. Es interfaz para que las pruebas usen
 * respuestas guionadas sin red.
 */
interface ClienteClaude
{
    /**
     * @param  list<array<string, mixed>>  $system  bloques de texto del system prompt
     * @param  list<array<string, mixed>>  $mensajes
     * @param  list<array<string, mixed>>  $herramientas
     *
     * @throws ClaudeException
     */
    public function crear(array $system, array $mensajes, array $herramientas = []): RespuestaClaude;
}
