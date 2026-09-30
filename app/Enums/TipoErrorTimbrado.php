<?php

namespace App\Enums;

/**
 * datos: facturapi.io rechazó lo enviado (4xx); se corrige y se reintenta.
 * pac: falla de facturapi.io, timeout o de conexión; se reintenta igual.
 */
enum TipoErrorTimbrado: string
{
    case Datos = 'datos';
    case Pac = 'pac';
}
