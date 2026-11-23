<?php

namespace App\Enums;

/**
 * Los dos agentes del número de WhatsApp (041, regla 1).
 */
enum AgenteWhatsapp: string
{
    case Ventas = 'ventas';
    case Facturacion = 'facturacion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Ventas => 'Ventas',
            self::Facturacion => 'Facturación',
        };
    }
}
