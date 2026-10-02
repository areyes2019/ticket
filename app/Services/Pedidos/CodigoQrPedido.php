<?php

namespace App\Services\Pedidos;

use App\Models\Pedido;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Un solo código por pedido, idéntico en el ticket y en la etiqueta: la URL
 * absoluta de la pantalla de entrega. La cámara del celular la abre sola, y
 * el destino exige sesión, así que el código no da acceso a nada.
 */
class CodigoQrPedido
{
    public function url(Pedido $pedido): string
    {
        return route('pedidos.entregar', $pedido);
    }

    /**
     * Bytes PNG, para copiarlo sobre el lienzo del ticket. Con escala 1 cada
     * módulo (incluido el margen) mide un pixel: así se calcula la escala
     * entera que cabe en un cuadro.
     */
    public function png(Pedido $pedido, int $escala = 10): string
    {
        return $this->generar($pedido, base64: false, escala: $escala);
    }

    /**
     * Para la etiqueta (HTML).
     */
    public function dataUri(Pedido $pedido): string
    {
        return $this->generar($pedido, base64: true, escala: 10);
    }

    private function generar(Pedido $pedido, bool $base64, int $escala): string
    {
        $opciones = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => $base64,
            'eccLevel' => EccLevel::M,
            'scale' => $escala,
            'quietzoneSize' => 4,
        ]);

        return (new QRCode($opciones))->render($this->url($pedido));
    }
}
