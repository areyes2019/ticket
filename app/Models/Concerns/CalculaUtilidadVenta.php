<?php

namespace App\Models\Concerns;

use App\Services\Documentos\CalculadoraTotalesDocumento;
use Illuminate\Database\Eloquent\Model;

/**
 * Utilidad de venta de un documento con líneas (cotización, pedido): lo que
 * le queda al usuario por el documento completo, para Tesorería (016).
 */
trait CalculaUtilidadVenta
{
    /**
     * Importe de cada línea (ya neto de descuentos, sin IVA) menos su costo al
     * venderse. Las líneas sin costo (libres o de un artículo que no lo tenía)
     * no se suman y la marcan como parcial; si ninguna lo tiene, la utilidad
     * no está disponible (null, distinto de 0).
     *
     * @return array{utilidad: string|null, parcial: bool}
     */
    public function utilidadVenta(): array
    {
        $conCosto = $this->lineas->filter(fn (Model $linea) => $linea->costo_unitario !== null);

        if ($conCosto->isEmpty()) {
            return ['utilidad' => null, 'parcial' => false];
        }

        $centavos = $conCosto->sum(fn (Model $linea) => CalculadoraTotalesDocumento::centavos($linea->importe)
            - CalculadoraTotalesDocumento::centavos($linea->costo_unitario) * $linea->cantidad);

        return [
            'utilidad' => CalculadoraTotalesDocumento::pesos($centavos),
            'parcial' => $conCosto->count() < $this->lineas->count(),
        ];
    }
}
