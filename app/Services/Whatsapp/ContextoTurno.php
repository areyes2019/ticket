<?php

namespace App\Services\Whatsapp;

use App\Enums\AgenteWhatsapp;
use App\Models\User;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappNumero;

/**
 * Lo que comparten las herramientas durante un turno (041, regla 20).
 */
final class ContextoTurno
{
    /**
     * Ventas que buscar_venta validó en esta conversación (regla 44); solo
     * esas se pueden timbrar.
     *
     * @var list<int>
     */
    public array $ventasValidadas = [];

    /**
     * Agente al que se transfirió en este turno (regla 2).
     */
    public ?AgenteWhatsapp $transferirA = null;

    public bool $pasoAHumano = false;

    public int $documentosEnviados = 0;

    public function __construct(
        public readonly WhatsappConversacion $conversacion,
    ) {
        $this->ventasValidadas = $conversacion->ventas_validadas ?? [];
    }

    public function numero(): WhatsappNumero
    {
        return $this->conversacion->numero;
    }

    public function user(): User
    {
        return $this->conversacion->user;
    }

    public function telefono10(): string
    {
        return $this->conversacion->telefono_10;
    }
}
