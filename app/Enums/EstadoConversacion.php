<?php

namespace App\Enums;

/**
 * Quién atiende una conversación de WhatsApp (041, regla 28).
 */
enum EstadoConversacion: string
{
    case Agente = 'agente';
    case Humano = 'humano';
    case Cerrada = 'cerrada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Agente => 'Agente',
            self::Humano => 'Requiere humano',
            self::Cerrada => 'Cerrada',
        };
    }

    public function claseEtiqueta(): string
    {
        return match ($this) {
            self::Agente => 'etiqueta-exitoso',
            self::Humano => 'etiqueta-suspendido',
            self::Cerrada => 'etiqueta-cerrada',
        };
    }
}
