<?php

namespace App\Models;

use App\Enums\MotivoMovimientoInventario;
use App\Enums\TipoMovimientoInventario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

/**
 * Historial de existencias, solo de consulta: un error se corrige con un
 * ajuste nuevo. Solo RegistradorInventario lo escribe. Los resultantes son el
 * estado del artículo después del movimiento, para auditar sin reconstruir.
 *
 * Se liga al artículo, no a la fila de existencias, para que el historial
 * sobreviva a quitar y volver a marcar el artículo.
 */
class MovimientoInventario extends Model
{
    protected $table = 'movimientos_inventario';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Articulo, $this>
     */
    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class)->withTrashed();
    }

    /**
     * Orden de compra, factura o cotización; null en un ajuste manual.
     *
     * @return MorphTo<Model, $this>
     */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Lo que el documento sacó y no ha regresado: sus salidas menos sus
     * entradas, por artículo, solo los netos positivos. Lo que devuelven la
     * edición, el borrado y el reembolso de una venta (019, 038).
     *
     * @return Collection<int, int> articulo_id => cantidad
     */
    public static function netosSalidos(Model $documento): Collection
    {
        return self::whereMorphedTo('documentable', $documento)
            ->whereIn('tipo', [TipoMovimientoInventario::Salida, TipoMovimientoInventario::Entrada])
            ->get()
            ->groupBy('articulo_id')
            ->map(fn (Collection $movimientos) => $movimientos->sum(
                fn (self $movimiento) => $movimiento->tipo === TipoMovimientoInventario::Salida ? $movimiento->cantidad : -$movimiento->cantidad
            ))
            ->filter(fn (int $neto) => $neto > 0);
    }

    /**
     * Enlace al documento que originó el movimiento, o null si fue manual.
     *
     * @return array{etiqueta: string, url: string}|null
     */
    public function documentoOrigen(): ?array
    {
        $documento = $this->documentable;

        return match (true) {
            $documento instanceof OrdenCompra => ['etiqueta' => $documento->folio_formateado, 'url' => route('ordenes-compra.show', $documento)],
            $documento instanceof Factura => ['etiqueta' => $documento->folioVisible(), 'url' => route('facturas.show', $documento)],
            $documento instanceof Cotizacion => ['etiqueta' => $documento->folio_formateado, 'url' => route('cotizaciones.show', $documento)],
            default => null,
        };
    }

    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimientoInventario::class,
            'motivo' => MotivoMovimientoInventario::class,
            'cantidad' => 'integer',
            'existencia_resultante' => 'integer',
            'faltante_resultante' => 'integer',
        ];
    }
}
