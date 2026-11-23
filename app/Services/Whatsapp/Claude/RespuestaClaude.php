<?php

namespace App\Services\Whatsapp\Claude;

/**
 * La respuesta de Claude en una forma que no depende del SDK. $contenido es
 * el contenido tal como llegó, para devolverlo sin tocar en el ciclo de
 * herramientas (los bloques de razonamiento deben regresar idénticos).
 */
final readonly class RespuestaClaude
{
    /**
     * @param  list<array{type: string, text?: string, id?: string, name?: string, input?: array<string, mixed>}>  $bloques
     * @param  list<mixed>  $contenido
     * @param  array{entrada: int, salida: int, cache_lectura: int, cache_escritura: int}  $uso
     */
    public function __construct(
        public ?string $stopReason,
        public array $bloques,
        public array $contenido,
        public array $uso,
        public string $modelo,
    ) {}

    public function texto(): string
    {
        return trim(implode("\n\n", array_map(
            fn (array $bloque) => $bloque['text'] ?? '',
            array_filter($this->bloques, fn (array $bloque) => $bloque['type'] === 'text'),
        )));
    }

    /**
     * @return list<array{type: string, id: string, name: string, input: array<string, mixed>}>
     */
    public function usosDeHerramienta(): array
    {
        return array_values(array_filter($this->bloques, fn (array $bloque) => $bloque['type'] === 'tool_use'));
    }

    public function pideHerramientas(): bool
    {
        return $this->stopReason === 'tool_use' && $this->usosDeHerramienta() !== [];
    }

    public function rechazada(): bool
    {
        return $this->stopReason === 'refusal';
    }
}
