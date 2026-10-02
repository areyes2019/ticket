<?php

namespace App\Enums;

enum EstadoOrdenCompra: string
{
    case Borrador = 'borrador';
    case Enviada = 'enviada';
    case Pagada = 'pagada';
    case Recibida = 'recibida';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Enviada => 'Enviada',
            self::Pagada => 'Pagada',
            self::Recibida => 'Recibida',
        };
    }

    /**
     * Clase de la etiqueta de color en listado y detalle.
     */
    public function claseEtiqueta(): string
    {
        return 'etiqueta-'.$this->value;
    }

    /**
     * Solo borrador y enviada se editan.
     */
    public function esEditable(): bool
    {
        return $this === self::Borrador || $this === self::Enviada;
    }

    /**
     * Toda orden no recibida impide eliminar a su proveedor, incluido el
     * borrador.
     */
    public function esActiva(): bool
    {
        return $this !== self::Recibida;
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

    /**
     * @return list<string>
     */
    public static function activos(): array
    {
        return array_values(array_map(
            fn (self $estado) => $estado->value,
            array_filter(self::cases(), fn (self $estado) => $estado->esActiva()),
        ));
    }
}
