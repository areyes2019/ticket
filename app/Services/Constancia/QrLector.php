<?php

namespace App\Services\Constancia;

use chillerlan\QRCode\Common\GDLuminanceSource;
use chillerlan\QRCode\QRCode;
use GdImage;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\PDFObject;
use Throwable;

/**
 * Lee el QR de la constancia sin ImageMagick ni Ghostscript: dentro de un PDF
 * el QR es una imagen guardada tal cual, así que se decodifica directamente
 * con GD, sin convertir la página a foto.
 */
class QrLector
{
    /**
     * Lado mínimo, en puntos, de una imagen que vale la pena examinar.
     */
    private const LADO_MINIMO = 50;

    /**
     * Margen blanco que se agrega alrededor: el QR guardado en el PDF puede
     * venir sin su zona de silencio y el lector la necesita.
     */
    private const MARGEN = 16;

    /**
     * Devuelve la dirección del validador del SAT que está en el PDF, o null.
     *
     * Una constancia trae más de un QR (el del sello digital también), así que
     * se elige por contenido: vale el que trae un D3 con la forma idCIF_RFC.
     */
    public function leerDePdf(Document $documento): ?string
    {
        foreach ($documento->getObjectsByType('XObject', 'Image') as $imagen) {
            $gd = $this->imagenDePdf($imagen);

            if ($gd === null) {
                continue;
            }

            $texto = $this->decodificar($gd);

            if ($texto !== null && IdentidadQr::desdeUrl($texto) !== null) {
                return $texto;
            }
        }

        return null;
    }

    /**
     * Devuelve el texto del QR de una foto (JPG/PNG), o null.
     */
    public function leerDeImagen(string $contenido): ?string
    {
        $gd = @imagecreatefromstring($contenido);

        return $gd === false ? null : $this->decodificar($gd);
    }

    /**
     * "Esta imagen no trae un QR legible" es un resultado previsto, no una falla.
     */
    private function decodificar(GdImage $gd): ?string
    {
        $lienzo = $this->conMargen($gd);

        try {
            $texto = trim((string) (new QRCode)->readFromSource(new GDLuminanceSource($lienzo)));
        } catch (Throwable) {
            return null;
        }

        return $texto === '' ? null : $texto;
    }

    private function conMargen(GdImage $gd): GdImage
    {
        $ancho = imagesx($gd);
        $alto = imagesy($gd);
        $lienzo = imagecreatetruecolor($ancho + self::MARGEN * 2, $alto + self::MARGEN * 2);

        imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));
        imagecopy($lienzo, $gd, self::MARGEN, self::MARGEN, 0, 0, $ancho, $alto);

        return $lienzo;
    }

    /**
     * Convierte una imagen del PDF a GD. Solo las cuadradas y de tamaño
     * suficiente: un QR siempre lo es, y así logotipos y firmas se descartan
     * sin gastar memoria en decodificarlos.
     */
    private function imagenDePdf(PDFObject $imagen): ?GdImage
    {
        $detalles = $imagen->getDetails(false);
        $ancho = (int) ($detalles['Width'] ?? 0);
        $alto = (int) ($detalles['Height'] ?? 0);

        if ($ancho !== $alto || $ancho < self::LADO_MINIMO) {
            return null;
        }

        $contenido = (string) $imagen->getContent();

        // JPEG (DCTDecode): la librería lo deja sin decodificar y GD lo lee.
        if (str_starts_with($contenido, "\xFF\xD8")) {
            $gd = @imagecreatefromstring($contenido);

            return $gd === false ? null : $gd;
        }

        return $this->imagenDePixeles($contenido, $ancho, $alto, (int) ($detalles['BitsPerComponent'] ?? 8), $this->parametrosDePrediccion($imagen));
    }

    /**
     * @return array{predictor: int, colores: ?int}
     */
    private function parametrosDePrediccion(PDFObject $imagen): array
    {
        $parametros = $imagen->getHeader()?->get('DecodeParms');
        $detalles = $parametros !== null && method_exists($parametros, 'getDetails') ? $parametros->getDetails() : [];

        // Con varios filtros, DecodeParms es una lista con un diccionario por filtro.
        if (array_is_list($detalles)) {
            $detalles = collect($detalles)->first(fn ($valor) => is_array($valor) && isset($valor['Predictor'])) ?? [];
        }

        return [
            'predictor' => (int) ($detalles['Predictor'] ?? 1),
            'colores' => isset($detalles['Colors']) ? (int) $detalles['Colors'] : null,
        ];
    }

    /**
     * Arma la imagen a partir de los pixeles crudos (FlateDecode ya aplicado).
     *
     * @param  array{predictor: int, colores: ?int}  $prediccion
     */
    private function imagenDePixeles(string $datos, int $ancho, int $alto, int $bits, array $prediccion): ?GdImage
    {
        if (! in_array($bits, [1, 8], true)) {
            return null;
        }

        $conPredictor = $prediccion['predictor'] >= 10;
        $canales = $prediccion['colores'] ?? $this->deducirCanales(strlen($datos), $ancho, $alto, $bits, $conPredictor);

        if (! in_array($canales, [1, 3, 4], true) || ($bits === 1 && $canales !== 1)) {
            return null;
        }

        $bytesPorFila = intdiv($ancho * $canales * $bits + 7, 8);

        if ($conPredictor) {
            $datos = $this->deshacerPredictorPng($datos, $bytesPorFila, max(1, intdiv($canales * $bits, 8)));
        }

        if ($datos === null || strlen($datos) < $bytesPorFila * $alto) {
            return null;
        }

        $gd = imagecreatetruecolor($ancho, $alto);

        for ($y = 0; $y < $alto; $y++) {
            $fila = $y * $bytesPorFila;

            for ($x = 0; $x < $ancho; $x++) {
                $gris = match (true) {
                    $bits === 1 => ((ord($datos[$fila + intdiv($x, 8)]) >> (7 - $x % 8)) & 1) * 255,
                    $canales === 1 => ord($datos[$fila + $x]),
                    $canales === 3 => intdiv(ord($datos[$fila + $x * 3]) + ord($datos[$fila + $x * 3 + 1]) + ord($datos[$fila + $x * 3 + 2]), 3),
                    default => 255 - min(255, ord($datos[$fila + $x * 4 + 3]) + intdiv(ord($datos[$fila + $x * 4]) + ord($datos[$fila + $x * 4 + 1]) + ord($datos[$fila + $x * 4 + 2]), 3)),
                };

                imagesetpixel($gd, $x, $y, ($gris << 16) | ($gris << 8) | $gris);
            }
        }

        return $gd;
    }

    private function deducirCanales(int $longitud, int $ancho, int $alto, int $bits, bool $conPredictor): ?int
    {
        foreach ([1, 3, 4] as $canales) {
            $porFila = intdiv($ancho * $canales * $bits + 7, 8) + ($conPredictor ? 1 : 0);

            if ($longitud === $porFila * $alto) {
                return $canales;
            }
        }

        return null;
    }

    /**
     * Deshace la predicción PNG (Predictor >= 10): cada fila empieza con un
     * byte que indica cómo se codificó respecto a la fila anterior. El SAT
     * guarda su QR sin predictor, pero un PDF vuelto a guardar con otra
     * herramienta suele usarlo, y sin deshacerlo el código sale ilegible.
     */
    private function deshacerPredictorPng(string $datos, int $bytesPorFila, int $bytesPorPixel): ?string
    {
        $filas = intdiv(strlen($datos), $bytesPorFila + 1);
        $anterior = array_fill(0, $bytesPorFila, 0);
        $resultado = '';

        for ($f = 0; $f < $filas; $f++) {
            $inicio = $f * ($bytesPorFila + 1);
            $tipo = ord($datos[$inicio]);
            $actual = [];

            for ($i = 0; $i < $bytesPorFila; $i++) {
                $byte = ord($datos[$inicio + 1 + $i]);
                $izquierda = $i >= $bytesPorPixel ? $actual[$i - $bytesPorPixel] : 0;
                $arriba = $anterior[$i];
                $arribaIzquierda = $i >= $bytesPorPixel ? $anterior[$i - $bytesPorPixel] : 0;

                $actual[$i] = match ($tipo) {
                    0 => $byte,
                    1 => $byte + $izquierda,
                    2 => $byte + $arriba,
                    3 => $byte + intdiv($izquierda + $arriba, 2),
                    4 => $byte + $this->paeth($izquierda, $arriba, $arribaIzquierda),
                    default => -1,
                };

                if ($actual[$i] < 0) {
                    return null;
                }

                $actual[$i] &= 0xFF;
            }

            $resultado .= pack('C*', ...$actual);
            $anterior = $actual;
        }

        return $resultado;
    }

    private function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return match (true) {
            $pa <= $pb && $pa <= $pc => $a,
            $pb <= $pc => $b,
            default => $c,
        };
    }
}
