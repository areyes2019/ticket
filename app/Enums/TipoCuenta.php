<?php

namespace App\Enums;

enum TipoCuenta: string
{
    case Efectivo = 'efectivo';
    case Banco = 'banco';
    case Digital = 'digital';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Efectivo => 'Efectivo',
            self::Banco => 'Banco',
            self::Digital => 'Digital',
            self::Otro => 'Otro',
        };
    }

    /**
     * Forma de pago del CFDI de una venta cobrada en una cuenta de este tipo
     * (autofactura de pedidos): el cliente no sabe a qué cuenta entró su dinero.
     */
    public function formaPagoSat(): FormaPago
    {
        return match ($this) {
            self::Efectivo => FormaPago::Efectivo,
            self::Banco, self::Digital => FormaPago::Transferencia,
            self::Otro => FormaPago::PorDefinir,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $tipo) => [$tipo->value => $tipo->etiqueta()])->all();
    }
}
