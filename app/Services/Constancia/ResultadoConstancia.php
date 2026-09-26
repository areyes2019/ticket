<?php

namespace App\Services\Constancia;

use App\Enums\FuenteConstancia;

/**
 * Datos extraídos de una constancia, listos para precargar el formulario.
 */
final readonly class ResultadoConstancia
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $advertencias
     */
    public function __construct(
        public FuenteConstancia $fuente,
        public array $data,
        public array $advertencias,
        public ?string $aviso,
    ) {}

    public function rfc(): string
    {
        return $this->data['rfc'];
    }
}
