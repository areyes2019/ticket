<?php

namespace App\Services\Whatsapp\Claude;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;

/**
 * Claude por la Messages API con el SDK oficial (041, decisión G). Usa la
 * ruta beta solo por el respaldo del servidor ante rechazos.
 */
class ClienteClaudeAnthropic implements ClienteClaude
{
    private const BETA_RESPALDO = 'server-side-fallback-2026-07-01';

    private ?Client $cliente = null;

    public function crear(array $system, array $mensajes, array $herramientas = []): RespuestaClaude
    {
        $modelo = config('whatsapp.claude.modelo');
        $respaldo = config('whatsapp.claude.fallbacks');

        try {
            $respuesta = $this->cliente()->beta->messages->create(
                maxTokens: config('whatsapp.claude.max_tokens'),
                messages: $mensajes,
                model: $modelo,
                fallbacks: $respaldo ? 'default' : null,
                outputConfig: ['effort' => config('whatsapp.claude.effort')],
                system: $system,
                thinking: ['type' => 'adaptive'],
                tools: $herramientas === [] ? null : $herramientas,
                betas: $respaldo ? [self::BETA_RESPALDO] : null,
            );
        } catch (APIConnectionException $error) {
            throw new ClaudeException('Sin conexión con Claude: '.$error->getMessage(), pasajera: true, previa: $error);
        } catch (APIStatusException $error) {
            $pasajera = $error->status === 429 || $error->status === 408 || ($error->status ?? 0) >= 500;

            throw new ClaudeException('Claude respondió '.$error->status.': '.($error->getAPIMessage() ?? $error->getMessage()), $pasajera, $error);
        } catch (AnthropicException $error) {
            throw new ClaudeException('Error con Claude: '.$error->getMessage(), previa: $error);
        }

        $bloques = [];

        foreach ($respuesta->content as $bloque) {
            match ($bloque->type) {
                'text' => $bloques[] = ['type' => 'text', 'text' => $bloque->text],
                'tool_use' => $bloques[] = ['type' => 'tool_use', 'id' => $bloque->id, 'name' => $bloque->name, 'input' => $bloque->input],
                default => null,
            };
        }

        return new RespuestaClaude(
            stopReason: $respuesta->stopReason,
            bloques: $bloques,
            contenido: $respuesta->content,
            uso: [
                'entrada' => $respuesta->usage->inputTokens,
                'salida' => $respuesta->usage->outputTokens,
                'cache_lectura' => $respuesta->usage->cacheReadInputTokens ?? 0,
                'cache_escritura' => $respuesta->usage->cacheCreationInputTokens ?? 0,
            ],
            modelo: $respuesta->model,
        );
    }

    private function cliente(): Client
    {
        return $this->cliente ??= new Client(apiKey: config('services.anthropic.key'));
    }
}
