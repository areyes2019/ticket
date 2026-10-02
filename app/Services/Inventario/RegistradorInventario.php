<?php

namespace App\Services\Inventario;

use App\Enums\MotivoMovimientoInventario;
use App\Enums\TipoMovimientoInventario;
use App\Models\Articulo;
use App\Models\Existencia;
use App\Models\Factura;
use App\Models\MovimientoInventario;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Única vía para tocar existencias y escribir su historial. Cada operación,
 * en una transacción:
 *
 * 1. bloquea los artículos involucrados, en orden de id (bloquear el artículo
 *    y no la fila cubre también el caso "la fila todavía no existe": dos
 *    operaciones a la vez no crean dos filas);
 * 2. aplica una de las tres reglas al par (existencia, faltante_pendiente);
 * 3. guarda la fila y el movimiento juntos: nunca queda una existencia que el
 *    historial no explique.
 *
 * Los artículos borrados lógicamente no se mueven.
 */
class RegistradorInventario
{
    /**
     * Regla 3: fija la cantidad final y borra el faltante (el usuario acaba
     * de contar). También es el alta manual: crea la fila o restaura la que se
     * había quitado.
     */
    public function ajustar(Articulo $articulo, int $cantidad, MotivoMovimientoInventario $motivo, ?string $nota): MovimientoInventario
    {
        return DB::transaction(function () use ($articulo, $cantidad, $motivo, $nota) {
            $bloqueado = $this->bloquear([$articulo->id])->sole();
            $fila = $this->filaParaEntrada($bloqueado);

            [$fila->existencia, $fila->faltante_pendiente] = self::fijar($cantidad);

            return $this->registrar($bloqueado, $fila, TipoMovimientoInventario::Ajuste, $motivo, $cantidad, $nota);
        });
    }

    /**
     * Regla 1 por artículo (recepción de orden de compra). Un artículo sin
     * fila entra en 0 antes de sumar: comprarlo es decidir almacenarlo.
     *
     * @param  iterable<Model>  $lineas  con articulo_id y cantidad
     */
    public function entradaPorDocumento(Model $documento, iterable $lineas, MotivoMovimientoInventario $motivo): void
    {
        $this->porArticulo(self::cantidadesPorArticulo($lineas), function (Articulo $articulo, int $cantidad) use ($documento, $motivo) {
            $fila = $this->filaParaEntrada($articulo);

            [$fila->existencia, $fila->faltante_pendiente] = self::entrar($fila->existencia, $fila->faltante_pendiente, $cantidad);

            $this->registrar($articulo, $fila, TipoMovimientoInventario::Entrada, $motivo, $cantidad, null, $documento);
        });
    }

    /**
     * Regla 2 por artículo (factura timbrada, cotización entregada). Nunca
     * bloquea. Con $creaFila = false (factura), un artículo sin fila viva no
     * genera movimiento ni se da de alta.
     *
     * @param  iterable<Model>  $lineas  con articulo_id y cantidad
     */
    public function salidaPorDocumento(Model $documento, iterable $lineas, MotivoMovimientoInventario $motivo, bool $creaFila): void
    {
        $this->porArticulo(self::cantidadesPorArticulo($lineas), function (Articulo $articulo, int $cantidad) use ($documento, $motivo, $creaFila) {
            $fila = $creaFila ? $this->filaParaEntrada($articulo) : Existencia::where('articulo_id', $articulo->id)->first();

            if ($fila === null) {
                return;
            }

            [$fila->existencia, $fila->faltante_pendiente] = self::salir($fila->existencia, $fila->faltante_pendiente, $cantidad);

            $this->registrar($articulo, $fila, TipoMovimientoInventario::Salida, $motivo, $cantidad, null, $documento);
        });
    }

    /**
     * Devuelve, como entradas normales, lo que la factura sacó al timbrarse:
     * sus movimientos venta_factura, no sus líneas. Así no devuelve lo que no
     * salió ni da de alta artículos al cancelar. Una factura con cotización
     * nunca movió nada. Si ya devolvió, no hace nada.
     */
    public function devolverFactura(Factura $factura): void
    {
        if ($factura->cotizacion_id !== null) {
            return;
        }

        DB::transaction(function () use ($factura) {
            // Con la factura bloqueada, dos refrescos a la vez no pasan los dos la guardia.
            Factura::whereKey($factura->id)->lockForUpdate()->first();
            $movimientos = MovimientoInventario::whereMorphedTo('documentable', $factura);

            if ((clone $movimientos)->where('motivo', MotivoMovimientoInventario::CancelacionFactura)->exists()) {
                return;
            }

            $this->entradaPorDocumento(
                $factura,
                (clone $movimientos)->where('motivo', MotivoMovimientoInventario::VentaFactura)->get(),
                MotivoMovimientoInventario::CancelacionFactura,
            );
        });
    }

    /**
     * Borrado lógico de la fila. No genera movimiento y no se bloquea aunque
     * queden piezas.
     */
    public function quitar(Articulo $articulo): void
    {
        DB::transaction(function () use ($articulo) {
            $this->bloquear([$articulo->id]);
            Existencia::where('articulo_id', $articulo->id)->first()?->delete();
        });
    }

    /**
     * Regla 1: primero salda el faltante; solo el resto sube la existencia.
     *
     * @return array{int, int} [existencia, faltante]
     */
    public static function entrar(int $existencia, int $faltante, int $cantidad): array
    {
        $saldado = min($cantidad, $faltante);

        return [$existencia + $cantidad - $saldado, $faltante - $saldado];
    }

    /**
     * Regla 2: la existencia toca fondo en 0 y el sobrante va al faltante.
     *
     * @return array{int, int} [existencia, faltante]
     */
    public static function salir(int $existencia, int $faltante, int $cantidad): array
    {
        $descontado = min($cantidad, $existencia);

        return [$existencia - $descontado, $faltante + $cantidad - $descontado];
    }

    /**
     * Regla 3: la cantidad capturada, sin faltante.
     *
     * @return array{int, int} [existencia, faltante]
     */
    public static function fijar(int $cantidad): array
    {
        return [$cantidad, 0];
    }

    /**
     * Suma por artículo, sin líneas libres. Red defensiva: dos líneas del
     * mismo artículo no se pisan y dejan un solo movimiento.
     *
     * @param  iterable<Model>  $lineas
     * @return array<int, int> articulo_id => cantidad
     */
    private static function cantidadesPorArticulo(iterable $lineas): array
    {
        $cantidades = [];

        foreach ($lineas as $linea) {
            if ($linea->articulo_id !== null) {
                $cantidades[$linea->articulo_id] = ($cantidades[$linea->articulo_id] ?? 0) + (int) $linea->cantidad;
            }
        }

        return $cantidades;
    }

    /**
     * @param  array<int, int>  $cantidades
     * @param  callable(Articulo, int): void  $aplicar
     */
    private function porArticulo(array $cantidades, callable $aplicar): void
    {
        if ($cantidades === []) {
            return;
        }

        DB::transaction(function () use ($cantidades, $aplicar) {
            foreach ($this->bloquear(array_keys($cantidades)) as $articulo) {
                $aplicar($articulo, $cantidades[$articulo->id]);
            }
        });
    }

    /**
     * Artículos vivos (sin los borrados lógicamente), bloqueados en orden de
     * id: dos documentos con los mismos artículos no se esperan mutuamente.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Articulo>
     */
    private function bloquear(array $ids): Collection
    {
        return Articulo::whereKey($ids)->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * La fila del artículo, creada en 0 si no existe o restaurada (con sus
     * números previos) si se había quitado. Se guarda al registrar.
     */
    private function filaParaEntrada(Articulo $articulo): Existencia
    {
        $fila = Existencia::withTrashed()->where('articulo_id', $articulo->id)->first() ?? new Existencia;
        $fila->articulo_id = $articulo->id;
        $fila->deleted_at = null;

        return $fila;
    }

    private function registrar(Articulo $articulo, Existencia $fila, TipoMovimientoInventario $tipo, MotivoMovimientoInventario $motivo, int $cantidad, ?string $nota, ?Model $documento = null): MovimientoInventario
    {
        $fila->save();

        $movimiento = new MovimientoInventario;
        $movimiento->forceFill([
            'user_id' => $articulo->user_id,
            'articulo_id' => $articulo->id,
            'tipo' => $tipo,
            'motivo' => $motivo,
            'cantidad' => $cantidad,
            'existencia_resultante' => $fila->existencia,
            'faltante_resultante' => $fila->faltante_pendiente,
            'nota' => $nota,
        ]);

        if ($documento !== null) {
            $movimiento->documentable()->associate($documento);
        }

        $movimiento->save();

        return $movimiento;
    }
}
