<?php

namespace App\Enums;

/**
 * De dónde salieron los datos de una Constancia de Situación Fiscal.
 */
enum FuenteConstancia: string
{
    /** El SAT respondió en vivo. */
    case SatQrDirect = 'SAT_QR_DIRECT';

    /** Texto copiado del PDF de la constancia. */
    case PdfTexto = 'PDF_TEXTO';

    /** Solo el RFC, leído del código QR. */
    case QrRfc = 'QR_RFC';

    public function confianza(): string
    {
        return match ($this) {
            self::SatQrDirect => 'oficial',
            self::PdfTexto => 'documento',
            self::QrRfc => 'parcial',
        };
    }
}
