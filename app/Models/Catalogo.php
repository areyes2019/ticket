<?php

namespace App\Models;

use Database\Factories\CatalogoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Agrupa artículos de un proveedor con un mismo descuento y la utilidad que
 * heredan los que no tienen una propia. El proveedor es fijo desde la creación
 * (CatalogoRequest no lo acepta en la edición).
 */
#[Fillable(['proveedor_id', 'nombre', 'descuento', 'utilidad_porcentaje'])]
class Catalogo extends Model
{
    /** @use HasFactory<CatalogoFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'descuento' => 0,
        'utilidad_porcentaje' => 0,
    ];

    /**
     * Al cambiar el descuento se recalculan todos sus artículos (cambia el
     * costo del que parten); al cambiar solo la utilidad, los que la heredan.
     * Se hace en PHP con la calculadora, no con un UPDATE: el techo a centavos
     * no es portable entre MySQL y SQLite y sería otra copia de la fórmula.
     */
    protected static function booted(): void
    {
        static::updated(function (Catalogo $catalogo) {
            $articulos = $catalogo->articulosPorRecalcular(
                $catalogo->wasChanged('descuento'),
                $catalogo->wasChanged('utilidad_porcentaje'),
            );

            if ($articulos === null) {
                return;
            }

            DB::transaction(fn () => $articulos->withTrashed()->lazyById()->each(function (Articulo $articulo) use ($catalogo) {
                $articulo->recalcularPrecio($catalogo);
                $articulo->saveQuietly();
            }));
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Incluye los proveedores eliminados, para que el catálogo siga mostrando
     * el nombre de su proveedor.
     *
     * @return BelongsTo<Proveedor, $this>
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class)->withTrashed();
    }

    /**
     * @return HasMany<Articulo, $this>
     */
    public function articulos(): HasMany
    {
        return $this->hasMany(Articulo::class);
    }

    /**
     * Catálogos que se pueden elegir para un artículo: los del proveedor
     * eliminado no se ofrecen. Ordenados por proveedor y nombre.
     *
     * @param  Builder<self>  $consulta
     */
    #[Scope]
    protected function disponibles(Builder $consulta): void
    {
        $consulta->select('catalogos.*')
            ->join('proveedores', 'proveedores.id', '=', 'catalogos.proveedor_id')
            ->whereNull('proveedores.deleted_at')
            ->orderBy('proveedores.nombre_comercial')
            ->orderBy('catalogos.nombre')
            ->with('proveedor');
    }

    /**
     * Cuántos artículos (no eliminados) cambiarían su precio de venta si el
     * catálogo pasara a este descuento y esta utilidad. Es exacto: compara el
     * precio nuevo con el guardado, así que un cambio que no mueve ningún
     * centavo no cuenta. Alimenta la confirmación del formulario.
     */
    public function articulosAfectados(float|string $descuento, float|string $utilidadPorcentaje): int
    {
        $articulos = $this->articulosPorRecalcular(
            round((float) $descuento, 2) !== round((float) $this->descuento, 2),
            round((float) $utilidadPorcentaje, 2) !== round((float) $this->utilidad_porcentaje, 2),
        );

        if ($articulos === null) {
            return 0;
        }

        return $articulos->get()
            ->filter(fn (Articulo $articulo) => $articulo->calcularPrecio($descuento, $utilidadPorcentaje)[1] !== round((float) $articulo->precio_unitario_sin_iva, 2))
            ->count();
    }

    /**
     * Artículos que mueve un cambio: todos si cambia el descuento, solo los
     * que heredan la utilidad si cambia la utilidad; null si no cambia nada.
     *
     * @return HasMany<Articulo, $this>|null
     */
    private function articulosPorRecalcular(bool $cambiaDescuento, bool $cambiaUtilidad): ?HasMany
    {
        return match (true) {
            $cambiaDescuento => $this->articulos(),
            $cambiaUtilidad => $this->articulos()->whereNull('utilidad_porcentaje'),
            default => null,
        };
    }

    /**
     * "Proveedor — Catálogo (15%)", para los selectores de catálogo.
     *
     * @return Attribute<string, never>
     */
    protected function etiqueta(): Attribute
    {
        return Attribute::get(fn (): string => "{$this->proveedor->nombre_comercial} — {$this->nombre} ({$this->descuento_texto})");
    }

    /**
     * Descuento sin ceros sobrantes: "15%", "12.5%".
     *
     * @return Attribute<string, never>
     */
    protected function descuentoTexto(): Attribute
    {
        return Attribute::get(fn (): string => self::porcentajeTexto($this->descuento));
    }

    /**
     * Utilidad sin ceros sobrantes: "25%", "122.5%".
     *
     * @return Attribute<string, never>
     */
    protected function utilidadTexto(): Attribute
    {
        return Attribute::get(fn (): string => self::porcentajeTexto($this->utilidad_porcentaje));
    }

    /**
     * Porcentaje sin ceros sobrantes: "15%", "12.5%".
     */
    public static function porcentajeTexto(float|string $porcentaje): string
    {
        return rtrim(rtrim(number_format((float) $porcentaje, 2, '.', ''), '0'), '.').'%';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'descuento' => 'decimal:2',
            'utilidad_porcentaje' => 'decimal:2',
        ];
    }
}
