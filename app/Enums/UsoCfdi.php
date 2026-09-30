<?php

namespace App\Enums;

/**
 * Catálogo SAT c_UsoCFDI (CFDI 4.0).
 */
enum UsoCfdi: string
{
    case AdquisicionMercancias = 'G01';
    case Devoluciones = 'G02';
    case GastosEnGeneral = 'G03';
    case Construcciones = 'I01';
    case MobiliarioOficina = 'I02';
    case EquipoTransporte = 'I03';
    case EquipoComputo = 'I04';
    case Herramental = 'I05';
    case ComunicacionesTelefonicas = 'I06';
    case ComunicacionesSatelitales = 'I07';
    case OtraMaquinaria = 'I08';
    case HonorariosMedicos = 'D01';
    case GastosMedicosIncapacidad = 'D02';
    case GastosFunerales = 'D03';
    case Donativos = 'D04';
    case InteresesHipotecarios = 'D05';
    case AportacionesSar = 'D06';
    case PrimasGastosMedicos = 'D07';
    case TransportacionEscolar = 'D08';
    case DepositosAhorro = 'D09';
    case ServiciosEducativos = 'D10';
    case SinEfectosFiscales = 'S01';
    case Pagos = 'CP01';
    case Nomina = 'CN01';

    public function descripcion(): string
    {
        return match ($this) {
            self::AdquisicionMercancias => 'Adquisición de mercancías',
            self::Devoluciones => 'Devoluciones, descuentos o bonificaciones',
            self::GastosEnGeneral => 'Gastos en general',
            self::Construcciones => 'Construcciones',
            self::MobiliarioOficina => 'Mobiliario y equipo de oficina por inversiones',
            self::EquipoTransporte => 'Equipo de transporte',
            self::EquipoComputo => 'Equipo de cómputo y accesorios',
            self::Herramental => 'Dados, troqueles, moldes, matrices y herramental',
            self::ComunicacionesTelefonicas => 'Comunicaciones telefónicas',
            self::ComunicacionesSatelitales => 'Comunicaciones satelitales',
            self::OtraMaquinaria => 'Otra maquinaria y equipo',
            self::HonorariosMedicos => 'Honorarios médicos, dentales y gastos hospitalarios',
            self::GastosMedicosIncapacidad => 'Gastos médicos por incapacidad o discapacidad',
            self::GastosFunerales => 'Gastos funerales',
            self::Donativos => 'Donativos',
            self::InteresesHipotecarios => 'Intereses reales efectivamente pagados por créditos hipotecarios (casa habitación)',
            self::AportacionesSar => 'Aportaciones voluntarias al SAR',
            self::PrimasGastosMedicos => 'Primas por seguros de gastos médicos',
            self::TransportacionEscolar => 'Gastos de transportación escolar obligatoria',
            self::DepositosAhorro => 'Depósitos en cuentas para el ahorro, primas que tengan como base planes de pensiones',
            self::ServiciosEducativos => 'Pagos por servicios educativos (colegiaturas)',
            self::SinEfectosFiscales => 'Sin efectos fiscales',
            self::Pagos => 'Pagos',
            self::Nomina => 'Nómina',
        };
    }

    /**
     * Usos válidos en un comprobante de Ingreso: sin Pagos (CP01) ni Nómina
     * (CN01).
     *
     * @return list<self>
     */
    public static function deFactura(): array
    {
        return array_values(array_filter(self::cases(), fn (self $uso) => ! in_array($uso, [self::Pagos, self::Nomina], true)));
    }

    /**
     * Opciones para el select de la factura: clave => "clave – descripción".
     *
     * @return array<string, string>
     */
    public static function opcionesFactura(): array
    {
        $opciones = [];

        foreach (self::deFactura() as $uso) {
            $opciones[$uso->value] = $uso->value.' – '.$uso->descripcion();
        }

        return $opciones;
    }
}
