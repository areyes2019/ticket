<?php

namespace App\Services\Pedidos;

use App\Models\Factura;
use App\Services\Facturacion\ResultadoTimbrado;

/**
 * Lo que pasó al enviar el formulario del portal: el enlace ya no servía
 * ($motivo), o se intentó timbrar ($timbrado) la $factura del pedido.
 */
final readonly class ResultadoAutofactura
{
    public function __construct(
        public ?string $motivo = null,
        public ?ResultadoTimbrado $timbrado = null,
        public ?Factura $factura = null,
        public bool $correoEnviado = false,
    ) {}

    public function timbrada(): bool
    {
        return $this->timbrado === ResultadoTimbrado::Timbrada;
    }
}
