<?php

namespace App\Services\Cotizaciones;

use App\Models\Cotizacion;
use App\Models\Emisor;
use App\Services\Documentos\LogoDocumento;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentoPdf;

/**
 * PDF de una cotización, generado al vuelo: nunca se guarda en disco. Lo usan
 * la descarga, la vista previa y el adjunto del correo.
 */
class GeneradorPdfCotizacion
{
    public function __construct(private LogoDocumento $logo) {}

    public function generar(Cotizacion $cotizacion): DocumentoPdf
    {
        // Con el subconjunto de fuentes solo se incrustan las letras usadas: el
        // archivo baja de ~900 KB a unas decenas, importante para WhatsApp.
        return Pdf::loadView('cotizaciones.pdf', $this->datos($cotizacion))
            ->setOption('isFontSubsettingEnabled', true)
            ->setPaper('letter');
    }

    /**
     * Lo que recibe la vista; separado para probar el contenido sin dompdf.
     *
     * @return array<string, mixed>
     */
    public function datos(Cotizacion $cotizacion): array
    {
        $cotizacion->loadMissing(['cliente', 'lineas.articulo', 'pagos']);

        return [
            'cotizacion' => $cotizacion,
            'emisor' => Emisor::actual(),
            'logo' => $this->logo->dataUri(),
            'logoMedidas' => $this->logo->medidas(),
        ];
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
