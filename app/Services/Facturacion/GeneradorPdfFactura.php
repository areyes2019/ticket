<?php

namespace App\Services\Facturacion;

use App\Models\Emisor;
use App\Models\Factura;
use App\Services\Documentos\LogoDocumento;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DocumentoPdf;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Representación impresa del CFDI, generada al vuelo solo con los datos
 * guardados (copias fiscales, sellos, líneas): nunca llama a facturapi.io ni
 * se guarda en disco. Lo usan la descarga, la vista previa, el correo y el
 * compartir.
 */
class GeneradorPdfFactura
{
    public function __construct(private LogoDocumento $logo) {}

    public function generar(Factura $factura): DocumentoPdf
    {
        return Pdf::loadView('facturas.pdf', $this->datos($factura))
            ->setOption('isFontSubsettingEnabled', true)
            ->setPaper('letter');
    }

    /**
     * Lo que recibe la vista; separado para probar el contenido sin dompdf.
     * Lo fiscal del emisor es la copia del timbrado; de la tabla emisor solo
     * salen los datos de contacto (026).
     *
     * @return array<string, mixed>
     */
    public function datos(Factura $factura): array
    {
        $factura->loadMissing(['cliente', 'lineas']);

        return [
            'factura' => $factura,
            'receptor' => $factura->receptor(),
            'emisor' => $factura->emisor(),
            'emisorContacto' => Emisor::actual(),
            'qr' => $this->codigoQr($factura),
            'urlVerificacion' => $factura->uuid_fiscal === null ? null : $this->urlVerificacion($factura),
            'importeEnLetra' => ImporteEnLetra::convertir($factura->total),
            'logo' => $this->logo->dataUri(),
            'logoMedidas' => $this->logo->medidas(),
        ];
    }

    public function contenido(Factura $factura): string
    {
        return $this->generar($factura)->output();
    }

    /**
     * URL de verificación del SAT: la que devolvió facturapi.io o, si no
     * llegó, la que arma el formato del Anexo 20.
     */
    public function urlVerificacion(Factura $factura): string
    {
        if (filled($factura->url_verificacion_sat)) {
            return $factura->url_verificacion_sat;
        }

        $total = str_pad(number_format((float) $factura->total, 6, '.', ''), 17, '0', STR_PAD_LEFT);

        return 'https://verificacfdi.facturaelectronica.sat.gob.mx/default.aspx?'.http_build_query([
            'id' => $factura->uuid_fiscal,
            're' => $factura->emisor_rfc,
            'rr' => $factura->receptor()['rfc'],
            'tt' => $total,
            'fe' => substr((string) $factura->sello_cfdi, -8),
        ]);
    }

    /**
     * PNG en data URI (Dompdf no dibuja bien los SVG en línea). Si no se puede
     * generar, null y queda en el log: el PDF sale con la URL en texto.
     */
    public function codigoQr(Factura $factura): ?string
    {
        if ($factura->uuid_fiscal === null) {
            return null;
        }

        try {
            $opciones = new QROptions([
                'outputInterface' => QRGdImagePNG::class,
                'outputBase64' => true,
                'eccLevel' => EccLevel::M,
                'scale' => 4,
            ]);

            return (new QRCode($opciones))->render($this->urlVerificacion($factura));
        } catch (Throwable $error) {
            Log::error('No se pudo generar el QR de la factura; el PDF lleva la URL en texto.', [
                'factura' => $factura->folio_formateado,
                'motivo' => $error->getMessage(),
            ]);

            return null;
        }
    }
}
