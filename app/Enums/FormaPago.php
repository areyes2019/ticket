<?php

namespace App\Enums;

/**
 * Catálogo SAT c_FormaPago (CFDI 4.0). En una cotización es solo informativo.
 */
enum FormaPago: string
{
    case Efectivo = '01';
    case ChequeNominativo = '02';
    case Transferencia = '03';
    case TarjetaCredito = '04';
    case MonederoElectronico = '05';
    case DineroElectronico = '06';
    case ValesDespensa = '08';
    case DacionEnPago = '12';
    case PagoPorSubrogacion = '13';
    case PagoPorConsignacion = '14';
    case Condonacion = '15';
    case Compensacion = '17';
    case Novacion = '23';
    case Confusion = '24';
    case RemisionDeDeuda = '25';
    case PrescripcionOCaducidad = '26';
    case ASatisfaccionDelAcreedor = '27';
    case TarjetaDebito = '28';
    case TarjetaServicios = '29';
    case AplicacionDeAnticipos = '30';
    case IntermediarioPagos = '31';
    case PorDefinir = '99';

    public function descripcion(): string
    {
        return match ($this) {
            self::Efectivo => 'Efectivo',
            self::ChequeNominativo => 'Cheque nominativo',
            self::Transferencia => 'Transferencia electrónica de fondos',
            self::TarjetaCredito => 'Tarjeta de crédito',
            self::MonederoElectronico => 'Monedero electrónico',
            self::DineroElectronico => 'Dinero electrónico',
            self::ValesDespensa => 'Vales de despensa',
            self::DacionEnPago => 'Dación en pago',
            self::PagoPorSubrogacion => 'Pago por subrogación',
            self::PagoPorConsignacion => 'Pago por consignación',
            self::Condonacion => 'Condonación',
            self::Compensacion => 'Compensación',
            self::Novacion => 'Novación',
            self::Confusion => 'Confusión',
            self::RemisionDeDeuda => 'Remisión de deuda',
            self::PrescripcionOCaducidad => 'Prescripción o caducidad',
            self::ASatisfaccionDelAcreedor => 'A satisfacción del acreedor',
            self::TarjetaDebito => 'Tarjeta de débito',
            self::TarjetaServicios => 'Tarjeta de servicios',
            self::AplicacionDeAnticipos => 'Aplicación de anticipos',
            self::IntermediarioPagos => 'Intermediario pagos',
            self::PorDefinir => 'Por definir',
        };
    }

    /**
     * Opciones para un select: clave => "clave – descripción".
     *
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        $opciones = [];

        foreach (self::cases() as $forma) {
            $opciones[$forma->value] = $forma->value.' – '.$forma->descripcion();
        }

        return $opciones;
    }
}
