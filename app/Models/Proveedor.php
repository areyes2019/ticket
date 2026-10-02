<?php

namespace App\Models;

use App\Enums\EstadoOrdenCompra;
use Database\Factories\ProveedorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['nombre_comercial', 'nombre_contacto', 'correo', 'telefono', 'rfc'])]
class Proveedor extends Model
{
    /** @use HasFactory<ProveedorFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Eloquent pluralizaría "Proveedor" como "proveedors".
     *
     * @var string
     */
    protected $table = 'proveedores';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Catalogo, $this>
     */
    public function catalogos(): HasMany
    {
        return $this->hasMany(Catalogo::class);
    }

    /**
     * @return HasMany<Articulo, $this>
     */
    public function articulos(): HasMany
    {
        return $this->hasMany(Articulo::class);
    }

    /**
     * @return HasMany<OrdenCompra, $this>
     */
    public function ordenesCompra(): HasMany
    {
        return $this->hasMany(OrdenCompra::class);
    }

    /**
     * Toda orden no recibida (incluido el borrador) impide eliminarlo. Usa
     * ordenes_activas_exists si el listado lo precargó con withExists().
     */
    public function tieneOrdenesActivas(): bool
    {
        if (array_key_exists('ordenes_activas_exists', $this->attributes)) {
            return (bool) $this->attributes['ordenes_activas_exists'];
        }

        return $this->ordenesCompra()->whereIn('estado', EstadoOrdenCompra::activos())->exists();
    }
}
