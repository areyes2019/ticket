<?php

namespace App\Enums;

/**
 * Qué hizo el reembolso (038) con la factura del documento: pidió su
 * cancelación ante el SAT, la dejó vigente o descartó la que no se había
 * timbrado. Sin factura, el reembolso no guarda decisión (null).
 */
enum DecisionFacturaReembolso: string
{
    case Cancelar = 'cancelar';
    case Conservar = 'conservar';
    case Descartar = 'descartar';
}
