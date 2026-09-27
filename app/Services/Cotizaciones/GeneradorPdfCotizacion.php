<?php

namespace App\Services\Cotizaciones;

use App\Models\Cotizacion;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentoPdf;

/**
 * PDF de una cotización, generado al vuelo: nunca se guarda en disco. Lo usan
 * la descarga, la vista previa y el adjunto del correo.
 */
class GeneradorPdfCotizacion
{
    public function generar(Cotizacion $cotizacion): DocumentoPdf
    {
        $cotizacion->loadMissing(['cliente', 'lineas', 'pagos']);

        // Con el subconjunto de fuentes solo se incrustan las letras usadas: el
        // archivo baja de ~900 KB a unas decenas, importante para WhatsApp.
        return Pdf::loadView('cotizaciones.pdf', ['cotizacion' => $cotizacion])
            ->setOption('isFontSubsettingEnabled', true)
            ->setPaper('letter');
    }

    public function contenido(Cotizacion $cotizacion): string
    {
        return $this->generar($cotizacion)->output();
    }

    public function nombreArchivo(Cotizacion $cotizacion): string
    {
        return 'cotizacion-'.$cotizacion->folio_formateado.'.pdf';
    }
}
