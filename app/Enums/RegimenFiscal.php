<?php

namespace App\Enums;

/**
 * Claves vigentes del catálogo SAT c_RegimenFiscal (CFDI 4.0).
 */
enum RegimenFiscal: string
{
    case GeneralPersonasMorales = '601';
    case PersonasMoralesSinFinesLucrativos = '603';
    case SueldosYSalarios = '605';
    case Arrendamiento = '606';
    case EnajenacionAdquisicionBienes = '607';
    case DemasIngresos = '608';
    case ResidentesExtranjero = '610';
    case Dividendos = '611';
    case ActividadesEmpresarialesProfesionales = '612';
    case Intereses = '614';
    case ObtencionPremios = '615';
    case SinObligacionesFiscales = '616';
    case SociedadesCooperativasProduccion = '620';
    case IncorporacionFiscal = '621';
    case ActividadesPrimarias = '622';
    case GruposSociedades = '623';
    case Coordinados = '624';
    case PlataformasTecnologicas = '625';
    case SimplificadoConfianza = '626';

    public function descripcion(): string
    {
        return match ($this) {
            self::GeneralPersonasMorales => 'General de Ley Personas Morales',
            self::PersonasMoralesSinFinesLucrativos => 'Personas Morales con Fines no Lucrativos',
            self::SueldosYSalarios => 'Sueldos y Salarios e Ingresos Asimilados a Salarios',
            self::Arrendamiento => 'Arrendamiento',
            self::EnajenacionAdquisicionBienes => 'Régimen de Enajenación o Adquisición de Bienes',
            self::DemasIngresos => 'Demás ingresos',
            self::ResidentesExtranjero => 'Residentes en el Extranjero sin Establecimiento Permanente en México',
            self::Dividendos => 'Ingresos por Dividendos (socios y accionistas)',
            self::ActividadesEmpresarialesProfesionales => 'Personas Físicas con Actividades Empresariales y Profesionales',
            self::Intereses => 'Ingresos por intereses',
            self::ObtencionPremios => 'Régimen de los ingresos por obtención de premios',
            self::SinObligacionesFiscales => 'Sin obligaciones fiscales',
            self::SociedadesCooperativasProduccion => 'Sociedades Cooperativas de Producción que optan por diferir sus ingresos',
            self::IncorporacionFiscal => 'Incorporación Fiscal',
            self::ActividadesPrimarias => 'Actividades Agrícolas, Ganaderas, Silvícolas y Pesqueras',
            self::GruposSociedades => 'Opcional para Grupos de Sociedades',
            self::Coordinados => 'Coordinados',
            self::PlataformasTecnologicas => 'Régimen de las Actividades Empresariales con ingresos a través de Plataformas Tecnológicas',
            self::SimplificadoConfianza => 'Régimen Simplificado de Confianza',
        };
    }

    /**
     * Opciones para el select del formulario: clave => "clave – descripción".
     *
     * @return array<string, string>
     */
    public static function opciones(): array
    {
        $opciones = [];

        foreach (self::cases() as $regimen) {
            $opciones[$regimen->value] = $regimen->value.' – '.$regimen->descripcion();
        }

        return $opciones;
    }
}
