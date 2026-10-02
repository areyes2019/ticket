<?php

namespace App\Services\OrdenesTrabajo;

use App\Models\OrdenTrabajo;
use App\Models\OrdenTrabajoLinea;
use App\Models\Pedido;
use App\Models\PedidoLinea;

/**
 * Editar la venta borra y vuelve a crear sus líneas (019), y con ellas se
 * irían los colores de su orden de trabajo (022). recordar() los guarda por
 * la clave de su línea antes de borrar; reaplicar() los pasa a la primera
 * línea nueva con la misma clave. Las líneas nuevas sin pareja quedan sin
 * color y los colores sin línea se pierden.
 */
class ConservadorColores
{
    /**
     * @return array<string, list<array{color_tinta: string, color_tinta_otro: string|null}>>
     */
    public function recordar(Pedido $pedido): array
    {
        $orden = OrdenTrabajo::where('pedido_id', $pedido->id)->with('lineas.pedidoLinea')->first();

        if ($orden === null) {
            return [];
        }

        $colores = [];

        foreach ($orden->lineas->sortBy(fn (OrdenTrabajoLinea $renglon) => $renglon->pedidoLinea->orden) as $renglon) {
            $colores[$this->clave($renglon->pedidoLinea)][] = [
                'color_tinta' => $renglon->color_tinta->value,
                'color_tinta_otro' => $renglon->color_tinta_otro,
            ];
        }

        return $colores;
    }

    /**
     * Después de crear las líneas nuevas, dentro de la misma transacción.
     *
     * @param  array<string, list<array{color_tinta: string, color_tinta_otro: string|null}>>  $colores
     */
    public function reaplicar(Pedido $pedido, array $colores): void
    {
        if ($colores === []) {
            return;
        }

        $orden = OrdenTrabajo::where('pedido_id', $pedido->id)->firstOrFail();

        foreach ($pedido->lineas()->get() as $linea) {
            $clave = $this->clave($linea);

            if (($colores[$clave] ?? []) !== []) {
                $orden->lineas()->create(['pedido_linea_id' => $linea->id, ...array_shift($colores[$clave])]);
            }
        }
    }

    /**
     * El artículo en las líneas de catálogo; la descripción en las libres.
     */
    private function clave(PedidoLinea $linea): string
    {
        return $linea->articulo_id !== null
            ? 'articulo:'.$linea->articulo_id
            : 'libre:'.mb_strtolower(trim($linea->descripcion));
    }
}
