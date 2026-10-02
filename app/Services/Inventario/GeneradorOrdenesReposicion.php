<?php

namespace App\Services\Inventario;

use App\Enums\EstadoOrdenCompra;
use App\Enums\ObjetoImpuesto;
use App\Enums\TasaIva;
use App\Models\Articulo;
use App\Models\Existencia;
use App\Models\OrdenCompra;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use Illuminate\Support\Facades\DB;

/**
 * Arma borradores de orden de compra con los artículos por pedir: uno por
 * proveedor (la copia del proveedor del catálogo que guarda el artículo), con
 * la cantidad sugerida y el costo del artículo. Nunca envía nada.
 */
class GeneradorOrdenesReposicion
{
    /**
     * Qué se crearía, sin crear nada: el resumen del diálogo de confirmación.
     * Los artículos con catálogo o proveedor borrado se omiten.
     *
     * @return array{grupos: list<array{proveedor: Proveedor, existencias: list<Existencia>}>, omitidos: list<array{articulo: Articulo, motivo: string}>}
     */
    public function plan(User $user): array
    {
        $grupos = [];
        $omitidos = [];

        $filas = Existencia::delUsuario($user)->soloPorPedir()
            ->with(['articulo.catalogo', 'articulo.proveedor'])
            ->orderBy('articulos.modelo')
            ->orderBy('existencias.id')
            ->get();

        foreach ($filas as $fila) {
            $articulo = $fila->articulo;

            $motivo = match (true) {
                $articulo->catalogo->trashed() => 'catálogo eliminado',
                $articulo->proveedor->trashed() => 'proveedor eliminado',
                default => null,
            };

            if ($motivo !== null) {
                $omitidos[] = ['articulo' => $articulo, 'motivo' => $motivo];

                continue;
            }

            $grupos[$articulo->proveedor_id] ??= ['proveedor' => $articulo->proveedor, 'existencias' => []];
            $grupos[$articulo->proveedor_id]['existencias'][] = $fila;
        }

        return ['grupos' => array_values($grupos), 'omitidos' => $omitidos];
    }

    /**
     * Crea los borradores en una sola transacción, con folio propio y totales
     * de la calculadora.
     *
     * @return array{ordenes: list<OrdenCompra>, omitidos: list<array{articulo: Articulo, motivo: string}>}
     */
    public function generar(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $plan = $this->plan($user);
            $ordenes = [];

            foreach ($plan['grupos'] as $grupo) {
                $ordenes[] = $this->crearOrden($user, $grupo['proveedor'], $grupo['existencias']);
            }

            return ['ordenes' => $ordenes, 'omitidos' => $plan['omitidos']];
        });
    }

    /**
     * @param  list<Existencia>  $existencias
     */
    private function crearOrden(User $user, Proveedor $proveedor, array $existencias): OrdenCompra
    {
        $lineas = array_map(fn (Existencia $fila) => [
            'articulo_id' => $fila->articulo_id,
            'cantidad' => $fila->cantidadSugerida(),
            'descripcion' => $fila->articulo->nombre,
            'modelo' => $fila->articulo->modelo,
            'precio_unitario' => $fila->articulo->costo_con_descuento,
            'descuento_tipo' => null,
            'descuento_valor' => null,
            // La misma precarga que el buscador de líneas de la orden.
            'tasa_iva' => $fila->articulo->objeto_imp === ObjetoImpuesto::SiObjeto ? TasaIva::Dieciseis->value : TasaIva::Exento->value,
        ], $existencias);

        $totales = CalculadoraTotalesDocumento::calcular($lineas);

        $orden = $user->ordenesCompra()->make(['proveedor_id' => $proveedor->id]);
        $orden->folio = OrdenCompra::siguienteFolio($user);
        $orden->estado = EstadoOrdenCompra::Borrador;
        $orden->aplicarTotales($totales);
        $orden->save();

        foreach ($lineas as $i => $linea) {
            $orden->lineas()->create([
                ...$linea,
                'orden' => $i + 1,
                'importe' => $totales['lineas'][$i]['importe'],
                'iva_importe' => $totales['lineas'][$i]['iva_importe'],
            ]);
        }

        return $orden;
    }
}
