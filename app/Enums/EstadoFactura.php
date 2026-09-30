<?php

namespace App\Enums;

/**
 * borrador solo existe entre el alta y el primer intento de timbrado;
 * pendiente es un timbrado fallido (reintentable). cancelada solo se alcanza
 * cuando facturapi.io confirma la cancelación (accepted).
 */
enum EstadoFactura: string
{
    case Borrador = 'borrador';
    case Pendiente = 'pendiente';
    case Timbrada = 'timbrada';
    case Cancelada = 'cancelada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Pendiente => 'Pendiente de timbrar',
            self::Timbrada => 'Timbrada',
            self::Cancelada => 'Cancelada',
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
