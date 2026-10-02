<?php

namespace App\Services\OrdenesCompra;

use App\Models\OrdenCompra;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentoPdf;

/**
 * PDF de una orden de compra, generado al vuelo: nunca se guarda en disco. Lo
 * usan la descarga, el compartir y el adjunto del correo.
 */
class GeneradorPdfOrdenCompra
{
    public function generar(OrdenCompra $orden): DocumentoPdf
    {
        $orden->loadMissing(['proveedor', 'lineas']);

        // Subconjunto de fuentes: el archivo pesa decenas de KB, no ~900 KB.
        return Pdf::loadView('ordenes-compra.pdf', ['orden' => $orden])
            ->setOption('isFontSubsettingEnabled', true)
            ->setPaper('letter');
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
