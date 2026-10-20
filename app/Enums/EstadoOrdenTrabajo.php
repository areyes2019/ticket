<?php

namespace App\Enums;

/**
 * Solo hacia adelante y sin regreso (022): en dibujo → en proceso → terminado
 * con "avanzar". Entregado no se alcanza avanzando: es la entrega de la venta
 * (022, corrección 1), y deshacerla regresa a terminado. Cancelada la deja
 * el reembolso de la venta (038) y es final.
 */
enum EstadoOrdenTrabajo: string
{
    case EnDibujo = 'en_dibujo';
    case EnProceso = 'en_proceso';
    case Terminado = 'terminado';
    case Entregado = 'entregado';
    case Cancelada = 'cancelada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EnDibujo => 'En dibujo',
            self::EnProceso => 'En proceso',
            self::Terminado => 'Terminado',
            self::Entregado => 'Entregado',
            self::Cancelada => 'Cancelada',
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
     * El estado al que lleva "avanzar"; null en terminado (lo que sigue es
     * entregar la venta), en entregado y en cancelada.
     */
    public function siguiente(): ?self
    {
        return match ($this) {
            self::EnDibujo => self::EnProceso,
            self::EnProceso => self::Terminado,
            self::Terminado, self::Entregado, self::Cancelada => null,
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
