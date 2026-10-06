<?php

namespace App\Services\Pedidos;

use App\Models\Pedido;
use App\Models\PedidoLinea;
use App\Services\Documentos\LogoDocumento;
use GdImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Ticket JPG estilo punto de venta, dibujado en el servidor con GD para que
 * salga idéntico desde cualquier aparato (019). No se guarda en ningún lado:
 * se dibuja cada vez que se pide, así que nunca muestra un saldo viejo.
 *
 * Primero se arma la lista de renglones y después se crea el lienzo con el
 * alto exacto.
 */
class GeneradorTicketPedido
{
    /**
     * Ancho útil de una térmica de 80 mm a 203 dpi.
     */
    public const ANCHO = 576;

    public const ANCHO_LOGO = 240;

    public const CALIDAD = 85;

    private const MARGEN = 24;

    /**
     * Tamaños en puntos (GD dibuja a 96 dpi).
     */
    private const TAM_NORMAL = 14;

    private const TAM_GRANDE = 19;

    private string $fuente;

    private string $fuenteNegrita;

    public function __construct()
    {
        $this->fuente = resource_path('fonts/DejaVuSansMono.ttf');
        $this->fuenteNegrita = resource_path('fonts/DejaVuSansMono-Bold.ttf');
    }

    /**
     * Bytes JPEG del ticket.
     */
    public function generar(Pedido $pedido): string
    {
        $pedido->loadMissing(['lineas', 'pagos']);

        $renglones = $this->renglones($pedido);
        $alto = self::MARGEN * 2 + array_sum(array_map(fn (array $renglon) => $this->altoDe($renglon), $renglones));

        $lienzo = imagecreatetruecolor(self::ANCHO, $alto);
        $blanco = imagecolorallocate($lienzo, 255, 255, 255);
        imagefill($lienzo, 0, 0, $blanco);

        $y = self::MARGEN;

        foreach ($renglones as $renglon) {
            $this->dibujar($lienzo, $renglon, $y);
            $y += $this->altoDe($renglon);
        }

        ob_start();
        imagejpeg($lienzo, null, self::CALIDAD);
        $jpeg = (string) ob_get_clean();
        imagedestroy($lienzo);

        foreach ($renglones as $renglon) {
            if ($renglon['tipo'] === 'imagen') {
                imagedestroy($renglon['imagen']);
            }
        }

        return $jpeg;
    }

    public function nombreArchivo(Pedido $pedido): string
    {
        return 'ticket-'.$pedido->folio_formateado.'.jpg';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function renglones(Pedido $pedido): array
    {
        $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
        $renglones = [];

        $logo = $this->logo();

        // El logo ya dice quién es el negocio; sin logo va el nombre.
        if ($logo !== null) {
            $renglones[] = ['tipo' => 'imagen', 'imagen' => $logo, 'margen' => 12];
        } else {
            foreach ($this->ajustar((string) config('negocio.nombre'), self::TAM_GRANDE) as $texto) {
                $renglones[] = $this->texto($texto, centro: true, negrita: true, tamano: self::TAM_GRANDE);
            }
        }

        $telefono = (string) config('negocio.telefono');

        foreach ([config('negocio.domicilio'), config('negocio.ciudad'), filled($telefono) ? 'Tel '.$telefono : '', config('negocio.sitio_web')] as $dato) {
            foreach ($this->ajustar((string) $dato, self::TAM_NORMAL) as $texto) {
                $renglones[] = $this->texto($texto, centro: true);
            }
        }

        $renglones[] = ['tipo' => 'separador'];
        $renglones[] = $this->texto('TICKET No. '.$pedido->numero_ticket, centro: true, negrita: true, tamano: self::TAM_GRANDE);
        $renglones[] = $this->texto($pedido->created_at->setTimezone(config('app.zona_negocio'))->format('d/m/Y H:i'), centro: true);

        foreach ($this->ajustar('Cliente: '.$pedido->cliente_nombre, self::TAM_NORMAL) as $texto) {
            $renglones[] = $this->texto($texto);
        }

        $renglones[] = ['tipo' => 'separador'];

        foreach ($pedido->lineas as $linea) {
            /** @var PedidoLinea $linea */
            $descripcion = trim($linea->descripcion.' '.($linea->modelo ?? ''));
            $renglones[] = $this->texto($this->recortar($descripcion, $this->columnas(self::TAM_NORMAL)));
            $renglones[] = $this->par('  '.$linea->cantidad.' x '.$pesos($linea->precioConIva()), $pesos($linea->importeConIva()));

            if ($linea->descuentoTexto() !== '') {
                $renglones[] = $this->texto('  Desc. '.$linea->descuentoTexto());
            }
        }

        $renglones[] = ['tipo' => 'separador'];
        $renglones[] = $this->par('Subtotal', $pesos($pedido->subtotal));

        if ((float) $pedido->total_descuento > 0) {
            $renglones[] = $this->par('Descuento', '-'.$pesos($pedido->total_descuento));
        }

        $renglones[] = $this->par('IVA 16%', $pesos($pedido->total_iva_16));
        $renglones[] = $this->par('TOTAL', $pesos($pedido->total), negrita: true, tamano: self::TAM_GRANDE);
        $renglones[] = ['tipo' => 'espacio', 'alto' => 8];
        $renglones[] = $this->par('Pagado', $pesos($pedido->totalPagado()));
        $renglones[] = $this->par('Saldo pendiente', $pesos($pedido->saldoPendiente()), negrita: true);

        $renglones[] = ['tipo' => 'espacio', 'alto' => 16];
        $renglones[] = $this->texto('No. '.$pedido->numero_ticket, centro: true, negrita: true, tamano: self::TAM_GRANDE);

        return $renglones;
    }

    /**
     * @return array<string, mixed>
     */
    private function texto(string $texto, bool $centro = false, bool $negrita = false, int $tamano = self::TAM_NORMAL): array
    {
        return ['tipo' => 'texto', 'texto' => $texto, 'centro' => $centro, 'negrita' => $negrita, 'tamano' => $tamano];
    }

    /**
     * Texto a la izquierda e importe alineado a la derecha.
     *
     * @return array<string, mixed>
     */
    private function par(string $izquierda, string $derecha, bool $negrita = false, int $tamano = self::TAM_NORMAL): array
    {
        return ['tipo' => 'par', 'izquierda' => $izquierda, 'derecha' => $derecha, 'negrita' => $negrita, 'tamano' => $tamano];
    }

    /**
     * @param  array<string, mixed>  $renglon
     */
    private function altoDe(array $renglon): int
    {
        return match ($renglon['tipo']) {
            'texto', 'par' => (int) round($renglon['tamano'] * 96 / 72 * 1.45),
            'separador' => 22,
            'espacio' => $renglon['alto'],
            'imagen' => imagesy($renglon['imagen']) + $renglon['margen'] * 2,
        };
    }

    /**
     * @param  array<string, mixed>  $renglon
     */
    private function dibujar(GdImage $lienzo, array $renglon, int $y): void
    {
        $negro = imagecolorallocate($lienzo, 0, 0, 0);
        $ancho = self::ANCHO - self::MARGEN * 2;

        switch ($renglon['tipo']) {
            case 'texto':
                $base = $y + (int) round($renglon['tamano'] * 96 / 72);
                $fuente = $renglon['negrita'] ? $this->fuenteNegrita : $this->fuente;
                $x = $renglon['centro']
                    ? (int) round((self::ANCHO - $this->anchoTexto($renglon['texto'], $renglon['tamano'], $fuente)) / 2)
                    : self::MARGEN;
                imagettftext($lienzo, $renglon['tamano'], 0, $x, $base, $negro, $fuente, $renglon['texto']);
                break;

            case 'par':
                $base = $y + (int) round($renglon['tamano'] * 96 / 72);
                $fuente = $renglon['negrita'] ? $this->fuenteNegrita : $this->fuente;
                imagettftext($lienzo, $renglon['tamano'], 0, self::MARGEN, $base, $negro, $fuente, $renglon['izquierda']);
                $x = self::MARGEN + $ancho - $this->anchoTexto($renglon['derecha'], $renglon['tamano'], $fuente);
                imagettftext($lienzo, $renglon['tamano'], 0, $x, $base, $negro, $fuente, $renglon['derecha']);
                break;

            case 'separador':
                imagesetstyle($lienzo, array_merge(array_fill(0, 6, $negro), array_fill(0, 4, IMG_COLOR_TRANSPARENT)));
                imageline($lienzo, self::MARGEN, $y + 11, self::MARGEN + $ancho, $y + 11, IMG_COLOR_STYLED);
                break;

            case 'imagen':
                $imagen = $renglon['imagen'];
                $x = (int) round((self::ANCHO - imagesx($imagen)) / 2);
                imagecopy($lienzo, $imagen, $x, $y + $renglon['margen'], 0, 0, imagesx($imagen), imagesy($imagen));
                break;
        }
    }

    private function anchoTexto(string $texto, int $tamano, string $fuente): int
    {
        $caja = imagettfbbox($tamano, 0, $fuente, $texto);

        return $caja === false ? 0 : abs($caja[2] - $caja[0]);
    }

    /**
     * Cuántos caracteres caben en un renglón (la fuente es monoespaciada).
     */
    private function columnas(int $tamano): int
    {
        $ancho = max(1, $this->anchoTexto(str_repeat('M', 10), $tamano, $this->fuente) / 10);

        return (int) floor((self::ANCHO - self::MARGEN * 2) / $ancho);
    }

    private function recortar(string $texto, int $columnas): string
    {
        return mb_strwidth($texto) > $columnas ? mb_strimwidth($texto, 0, $columnas - 1).'…' : $texto;
    }

    /**
     * Parte un texto largo en renglones por palabras. Vacío → sin renglones.
     *
     * @return list<string>
     */
    private function ajustar(string $texto, int $tamano): array
    {
        $texto = trim($texto);

        if ($texto === '') {
            return [];
        }

        $columnas = $this->columnas($tamano);
        $renglones = [];
        $actual = '';

        foreach (preg_split('/\s+/u', $texto) as $palabra) {
            $propuesta = $actual === '' ? $palabra : $actual.' '.$palabra;

            if (mb_strwidth($propuesta) <= $columnas) {
                $actual = $propuesta;

                continue;
            }

            if ($actual !== '') {
                $renglones[] = $actual;
            }

            $actual = $this->recortar($palabra, $columnas);
        }

        $renglones[] = $actual;

        return $renglones;
    }

    /**
     * Logo del negocio reducido a ANCHO_LOGO como máximo: el configurado o, sin
     * él, el de los documentos. El ticket nunca falla por su logo: si no está
     * o GD no lo lee, se omite.
     */
    private function logo(): ?GdImage
    {
        $ruta = config('negocio.logo');

        try {
            if (blank($ruta)) {
                $archivo = public_path(LogoDocumento::RUTA);
                $contenido = is_file($archivo) ? file_get_contents($archivo) : false;
            } else {
                $disco = Storage::disk('public');
                $contenido = $disco->exists($ruta) ? $disco->get($ruta) : false;
            }

            if (! is_string($contenido)) {
                return null;
            }

            $original = @imagecreatefromstring($contenido);

            if ($original === false) {
                return null;
            }

            $escala = min(1, self::ANCHO_LOGO / imagesx($original));
            $ancho = max(1, (int) round(imagesx($original) * $escala));
            $alto = max(1, (int) round(imagesy($original) * $escala));

            // Fondo blanco: un PNG transparente no debe quedar negro en el JPG.
            $logo = imagecreatetruecolor($ancho, $alto);
            imagefill($logo, 0, 0, imagecolorallocate($logo, 255, 255, 255));
            imagecopyresampled($logo, $original, 0, 0, 0, 0, $ancho, $alto, imagesx($original), imagesy($original));
            imagedestroy($original);

            return $logo;
        } catch (Throwable $error) {
            Log::warning('No se pudo dibujar el logo del ticket.', ['error' => $error->getMessage()]);

            return null;
        }
    }
}
