<?php

namespace App\Services\OrdenesCompra;

use App\Models\Emisor;
use App\Models\OrdenCompra;
use App\Services\Documentos\LogoDocumento;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentoPdf;

/**
 * PDF de una orden de compra, generado al vuelo: nunca se guarda en disco. Lo
 * usan la descarga, el compartir y el adjunto del correo.
 */
class GeneradorPdfOrdenCompra
{
    public function __construct(private LogoDocumento $logo) {}

    public function generar(OrdenCompra $orden): DocumentoPdf
    {
        // Subconjunto de fuentes: el archivo pesa decenas de KB, no ~900 KB.
        return Pdf::loadView('ordenes-compra.pdf', $this->datos($orden))
            ->setOption('isFontSubsettingEnabled', true)
            ->setPaper('letter');
    }

    /**
     * Lo que recibe la vista; separado para probar el contenido sin dompdf.
     *
     * @return array<string, mixed>
     */
    public function datos(OrdenCompra $orden): array
    {
        $orden->loadMissing(['proveedor', 'lineas.articulo']);

        return [
            'orden' => $orden,
            'emisor' => Emisor::actual(),
            'logo' => $this->logo->dataUri(),
            'logoMedidas' => $this->logo->medidas(),
        ];
    }

    public function contenido(OrdenCompra $orden): string
    {
        return $this->generar($orden)->output();
    }

    public function nombreArchivo(OrdenCompra $orden): string
    {
        return 'orden-compra-'.$orden->folio_formateado.'.pdf';
    }
}
