<?php

namespace App\Enums;

enum MotivoMovimientoInventario: string
{
    case RecepcionOrden = 'recepcion_orden';
    case VentaFactura = 'venta_factura';
    case VentaCotizacion = 'venta_cotizacion';
    case VentaPedido = 'venta_pedido';
    case CorreccionPedido = 'correccion_pedido';
    case CancelacionFactura = 'cancelacion_factura';
    case ConteoFisico = 'conteo_fisico';
    case Merma = 'merma';
    case Devolucion = 'devolucion';
    case EntradaInicial = 'entrada_inicial';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::RecepcionOrden => 'Recepción de orden de compra',
            self::VentaFactura => 'Venta facturada',
            self::VentaCotizacion => 'Cotización entregada',
            self::VentaPedido => 'Venta de mostrador',
            self::CorreccionPedido => 'Corrección de pedido',
            self::CancelacionFactura => 'Cancelación de factura',
            self::ConteoFisico => 'Conteo físico',
            self::Merma => 'Merma',
            self::Devolucion => 'Devolución',
            self::EntradaInicial => 'Entrada inicial',
            self::Otro => 'Otro',
        };
    }

    /**
     * Los que puede elegir el usuario en un ajuste. Los demás solo los
     * escribe el sistema: no se falsifica el origen de un movimiento.
     *
     * @return list<self>
     */
    public static function manuales(): array
    {
        return [self::ConteoFisico, self::Merma, self::Devolucion, self::EntradaInicial, self::Otro];
    }

    /**
     * @return array<string, string>
     */
    public static function opcionesManuales(): array
    {
        return collect(self::manuales())->mapWithKeys(fn (self $motivo) => [$motivo->value => $motivo->etiqueta()])->all();
    }
}
