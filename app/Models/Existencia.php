<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un artículo marcado "en existencias". Sin fila, el artículo no es
 * inventario. Ninguna columna es asignable: solo RegistradorInventario las
 * escribe, en la misma transacción que el movimiento que lo explica (los
 * umbrales los escribe ExistenciaController, porque no mueven piezas).
 *
 * Quitar de existencias es borrado lógico; volver a marcar restaura la misma
 * fila con sus números.
 */
class Existencia extends Model
{
    use SoftDeletes;

    protected $table = 'existencias';

    /**
     * Una fila nueva nace en ceros, como en la base de datos.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'existencia' => 0,
        'faltante_pendiente' => 0,
        'minimo' => 0,
    ];

    /**
     * @return BelongsTo<Articulo, $this>
     */
    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class);
    }

    /**
     * Estrictamente menor que el mínimo: sin máximo el techo es el propio
     * mínimo, y con "menor o igual" un artículo en su mínimo exacto quedaría
     * por pedir con sugerencia 0.
     */
    public function porPedir(): bool
    {
        return ($this->minimo > 0 && $this->existencia < $this->minimo) || $this->faltante_pendiente > 0;
    }

    /**
     * Rellenar hasta el techo (máximo, o mínimo si no hay) y cubrir el
     * faltante.
     */
    public function cantidadSugerida(): int
    {
        return max(($this->maximo ?? $this->minimo) - $this->existencia, 0) + $this->faltante_pendiente;
    }

    /**
     * Valuación al costo de hoy, sin IVA (las mismas fórmulas que los totales
     * del listado). Requiere el artículo cargado.
     */
    public function invertido(): float
    {
        return round($this->existencia * (float) $this->articulo->costo_con_descuento, 2);
    }

    public function beneficio(): float
    {
        return round($this->existencia * ((float) $this->articulo->precio_unitario_sin_iva - (float) $this->articulo->costo_con_descuento), 2);
    }

    /**
     * La misma condición que porPedir(), en SQL.
     *
     * @param  Builder<self>  $consulta
     */
    #[Scope]
    protected function soloPorPedir(Builder $consulta): void
    {
        $consulta->where(fn (Builder $q) => $q
            ->where(fn (Builder $bajo) => $bajo->where('existencias.minimo', '>', 0)->whereColumn('existencias.existencia', '<', 'existencias.minimo'))
            ->orWhere('existencias.faltante_pendiente', '>', 0));
    }

    /**
     * Base de toda consulta de existencias del usuario: filas vivas de sus
     * artículos sin borrar, con el artículo unido para filtrar, ordenar y
     * sumar sobre sus columnas.
     *
     * @param  Builder<self>  $consulta
     */
    #[Scope]
    protected function delUsuario(Builder $consulta, User $user): void
    {
        $consulta->join('articulos', 'articulos.id', '=', 'existencias.articulo_id')
            ->where('articulos.user_id', $user->id)
            ->whereNull('articulos.deleted_at')
            ->select('existencias.*');
    }

    protected function casts(): array
    {
        return [
            'existencia' => 'integer',
            'faltante_pendiente' => 'integer',
            'minimo' => 'integer',
            'maximo' => 'integer',
        ];
    }
}
