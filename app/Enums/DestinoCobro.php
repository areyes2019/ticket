<?php

namespace App\Enums;

/**
 * Lo que nace con el primer pago de una cotización (029). Una cotización de
 * puros suministros o de un distribuidor no crea nada: se cobra, se entrega
 * y se factura desde ella misma. Un cliente normal con algo de producción
 * tiene su venta y su orden de trabajo. No hay "venta sin orden".
 */
enum DestinoCobro: string
{
    case SinVentaDistribuidor = 'sin_venta_distribuidor';
    case SinVentaSuministros = 'sin_venta_suministros';
    case VentaYOrden = 'venta_y_orden';

    public function creaVenta(): bool
    {
        return $this === self::VentaYOrden;
    }

    public function creaOrden(): bool
    {
        return $this === self::VentaYOrden;
    }

    /**
     * Aviso de la ventana del pago, antes de registrarlo.
     */
    public function aviso(): string
    {
        return match ($this) {
            self::SinVentaDistribuidor => 'Cliente distribuidor: no se creará venta ni orden de trabajo.',
            self::SinVentaSuministros => 'Solo suministros: no se creará venta ni orden de trabajo.',
            self::VentaYOrden => 'Al registrar este pago se creará la venta y su orden de trabajo.',
        };
    }

    /**
     * Razón que acompaña al mensaje después de cobrar cuando no nace venta.
     */
    public function razonSinVenta(): ?string
    {
        return match ($this) {
            self::SinVentaDistribuidor => 'Cliente distribuidor: no se creó venta.',
            self::SinVentaSuministros => 'Solo suministros: no se creó venta.',
            self::VentaYOrden => null,
        };
    }
}
