<?php

namespace App\Enums;

/**
 * pendiente, anticipo y pagado se derivan solos de la suma de los pagos
 * (Pedido::recalcularEstado()); entregado solo lo escribe la entrega.
 */
enum EstadoPedido: string
{
    case Pendiente = 'pendiente';
    case Anticipo = 'anticipo';
    case Pagado = 'pagado';
    case Entregado = 'entregado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Anticipo => 'Anticipo',
            self::Pagado => 'Pagado',
            self::Entregado => 'Entregado',
        };
    }

    /**
     * Clase de la etiqueta de color en listado y detalle.
     */
    public function claseEtiqueta(): string
    {
        return 'etiqueta-pedido-'.$this->value;
    }

    /**
     * Pagado y entregado quedan congelados: el ticket ya salió con esas líneas.
     */
    public function esEditable(): bool
    {
        return $this === self::Pendiente || $this === self::Anticipo;
    }

    /**
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $estado) => [$estado->value => $estado->etiqueta()])->all();
    }
}
