<?php

namespace App\Enums;

/**
 * Solo hacia adelante y sin regreso (022): en dibujo → en proceso → terminado.
 */
enum EstadoOrdenTrabajo: string
{
    case EnDibujo = 'en_dibujo';
    case EnProceso = 'en_proceso';
    case Terminado = 'terminado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EnDibujo => 'En dibujo',
            self::EnProceso => 'En proceso',
            self::Terminado => 'Terminado',
        };
    }

    /**
     * Clase de la etiqueta de color en listado y detalle.
     */
    public function claseEtiqueta(): string
    {
        return 'etiqueta-orden-'.str_replace('_', '-', $this->value);
    }

    /**
     * El estado al que lleva "avanzar"; null en el último.
     */
    public function siguiente(): ?self
    {
        return match ($this) {
            self::EnDibujo => self::EnProceso,
            self::EnProceso => self::Terminado,
            self::Terminado => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $estado) => [$estado->value => $estado->etiqueta()])->all();
    }
}
