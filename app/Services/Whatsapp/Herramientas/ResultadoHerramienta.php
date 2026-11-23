<?php

namespace App\Services\Whatsapp\Herramientas;

/**
 * Lo que la herramienta le devuelve al agente ($datos, en JSON) y lo que se
 * ve plegado en la bandeja ($resumen, regla 54).
 */
final readonly class ResultadoHerramienta
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function __construct(
        public array $datos,
        public string $resumen,
        public bool $error = false,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public static function ok(array $datos, string $resumen): self
    {
        return new self($datos, $resumen);
    }

    public static function error(string $motivo, ?string $resumen = null): self
    {
        return new self(['error' => $motivo], $resumen ?? 'error: '.$motivo, true);
    }

    public function json(): string
    {
        return json_encode($this->datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
