<?php

namespace App\Services\Whatsapp;

final readonly class ArchivoDescargado
{
    public function __construct(
        public string $contenido,
        public string $mime,
    ) {}
}
