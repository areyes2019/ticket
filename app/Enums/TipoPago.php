<?php

namespace App\Enums;

enum TipoPago: string
{
    case Anticipo = 'anticipo';
    case Saldo = 'saldo';
    case PagoTotal = 'pago_total';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Anticipo => 'Anticipo',
            self::Saldo => 'Saldo',
            self::PagoTotal => 'Pago total',
        };
    }
}
