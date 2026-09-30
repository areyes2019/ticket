<?php

namespace App\Services\Facturacion;

enum ResultadoTimbrado
{
    case Timbrada;
    case ErrorDatos;
    case ErrorPac;

    /**
     * Otra petición tiene el candado de esta factura.
     */
    case EnCurso;

    /**
     * La factura ya no estaba en borrador/pendiente al tomar el candado.
     */
    case SinCambio;
}
