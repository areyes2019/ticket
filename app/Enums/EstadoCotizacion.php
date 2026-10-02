<?php

namespace App\Enums;

enum EstadoCotizacion: string
{
    case Borrador = 'borrador';
    case Enviada = 'enviada';
    case Aceptada = 'aceptada';
    case Pagada = 'pagada';
    case ProductoEntregado = 'producto_entregado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Enviada => 'Enviada',
            self::Aceptada => 'Aceptada',
            self::Pagada => 'Pagada',
            self::ProductoEntregado => 'Entregada',
        };
    }

    /**
     * Clase de la etiqueta de color en listado y detalle.
     */
    public function claseEtiqueta(): string
    {
        return 'etiqueta-'.str_replace('_', '-', $this->value);
    }

    /**
     * Solo borrador y enviada se editan, se eliminan y caducan.
     */
    public function esEditable(): bool
    {
        return $this === self::Borrador || $this === self::Enviada;
    }

    /**
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        $opciones = [];

        foreach (self::cases() as $estado) {
            $opciones[$estado->value] = $estado->etiqueta();
        }

        return $opciones;
    }
}
