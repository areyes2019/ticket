<?php

namespace App\Models;

use App\Enums\ObjetoImpuesto;
use Database\Factories\ArticuloFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'proveedor_id',
    'nombre',
    'modelo',
    'clave_prod_serv',
    'clave_unidad',
    'objeto_imp',
    'precio_unitario_sin_iva',
])]
class Articulo extends Model
{
    /** @use HasFactory<ArticuloFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Tasa general de IVA. Único lugar donde se define.
     */
    public const TASA_IVA = 0.16;

    /**
     * Columnas que se pueden filtrar desde el listado.
     */
    public const FILTROS = ['nombre', 'modelo'];

    /**
     * Columnas por las que se puede ordenar el listado.
     */
    public const ORDENES = ['nombre', 'modelo', 'proveedor', 'precio'];

    /**
     * Filas por página que se pueden elegir en el listado.
     */
    public const POR_PAGINA = [10, 25, 50, 100];

    /**
     * Columnas del CSV, idénticas en importación y exportación.
     */
    public const COLUMNAS_CSV = ['nombre', 'modelo', 'clave_prod_serv', 'clave_unidad', 'objeto_imp', 'precio_unitario_sin_iva'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Incluye los proveedores eliminados, para que el artículo siga mostrando
     * el nombre de su proveedor.
     *
     * @return BelongsTo<Proveedor, $this>
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class)->withTrashed();
    }

    /**
     * Aplica los filtros del listado (coincidencia parcial, combinados con Y).
     * Los filtros vacíos se ignoran.
     *
     * @param  Builder<self>  $consulta
     * @param  array<string, string>  $filtros
     */
    #[Scope]
    protected function filtrar(Builder $consulta, array $filtros): void
    {
        foreach (self::FILTROS as $columna) {
            $termino = trim($filtros[$columna] ?? '');

            if ($termino !== '') {
                $consulta->where($columna, 'like', "%{$termino}%");
            }
        }
    }

    /**
     * Ordena por una de las columnas de ORDENES; desempata por id para que la
     * paginación sea estable.
     *
     * @param  Builder<self>  $consulta
     */
    #[Scope]
    protected function ordenar(Builder $consulta, string $columna, string $direccion): void
    {
        match ($columna) {
            'proveedor' => $consulta->orderBy(
                Proveedor::withTrashed()
                    ->select('nombre_comercial')
                    ->whereColumn('proveedores.id', 'articulos.proveedor_id'),
                $direccion
            ),
            'precio' => $consulta->orderBy('precio_unitario_sin_iva', $direccion),
            default => $consulta->orderBy($columna, $direccion),
        };

        $consulta->orderBy('id', $direccion);
    }

    /**
     * Precio con la tasa general de IVA, redondeado a centavos. No se guarda.
     *
     * @return Attribute<float, never>
     */
    protected function precioUnitarioConIva(): Attribute
    {
        // El redondeo previo a 6 decimales quita el ruido de punto flotante
        // (100 × 1.16 = 116.00000000000001) antes de redondear a centavos.
        return Attribute::get(fn (): float => round(round((float) $this->precio_unitario_sin_iva * (1 + self::TASA_IVA), 6), 2));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'objeto_imp' => ObjetoImpuesto::class,
            'precio_unitario_sin_iva' => 'decimal:2',
        ];
    }
}
