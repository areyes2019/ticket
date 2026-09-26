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
 * Agrupa artículos de un proveedor con un mismo descuento. El proveedor es fijo
 * desde la creación (CatalogoRequest no lo acepta en la edición).
 */
#[Fillable(['proveedor_id', 'nombre', 'descuento'])]
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
    ];

    /**
     * Al cambiar el descuento se recalcula el precio con descuento de todos sus
     * artículos con un solo UPDATE. El literal 100.0 evita la división entera
     * de SQLite (20 / 100 = 0).
     */
    protected static function booted(): void
    {
        static::updated(function (Catalogo $catalogo) {
            if (! $catalogo->wasChanged('descuento')) {
                return;
            }

            $descuento = sprintf('%.2F', (float) $catalogo->descuento);

            Articulo::withTrashed()
                ->where('catalogo_id', $catalogo->id)
                ->update(['precio_con_descuento' => DB::raw("ROUND(precio_unitario_sin_iva * (1 - {$descuento} / 100.0), 2)")]);
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
     * Precio con el descuento del catálogo, redondeado a centavos igual que el
     * ROUND de MySQL. El redondeo previo a 6 decimales quita el ruido de punto
     * flotante (10.05 × 0.9 = 9.044999…) antes de redondear.
     */
    public function precioConDescuento(float|string $precio): float
    {
        return round(round((float) $precio * (1 - (float) $this->descuento / 100), 6), 2);
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
        return Attribute::get(fn (): string => rtrim(rtrim(number_format((float) $this->descuento, 2, '.', ''), '0'), '.').'%');
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
        ];
    }
}
