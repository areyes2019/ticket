<?php

namespace App\Models;

use App\Enums\ObjetoImpuesto;
use App\Services\Articulos\CalculadoraPrecioArticulo;
use Database\Factories\ArticuloFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * proveedor_id es una copia del proveedor del catálogo que escribe el modelo
 * (ver booted()); el formulario y la importación solo envían catalogo_id.
 *
 * costo_con_descuento y precio_unitario_sin_iva también los escribe solo el
 * modelo, a partir del precio de lista y la utilidad (ver recalcularPrecio()).
 *
 * imagen_ruta la escribe solo ProcesadorImagenArticulo, al guardar el archivo.
 */
#[Fillable([
    'catalogo_id',
    'nombre',
    'modelo',
    'clave_prod_serv',
    'clave_unidad',
    'objeto_imp',
    'precio_proveedor',
    'utilidad_porcentaje',
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
     * Porcentaje de utilidad a partir del cual el formulario avisa (sin
     * bloquear) que puede haber un cero de más. Único lugar donde se define.
     */
    public const UMBRAL_UTILIDAD_ALTA = 400;

    /**
     * Columnas que se pueden filtrar desde el listado.
     */
    public const FILTROS = ['nombre', 'modelo'];

    /**
     * Columnas por las que se puede ordenar el listado.
     */
    public const ORDENES = ['nombre', 'modelo', 'proveedor', 'catalogo', 'costo', 'precio'];

    /**
     * Filas por página que se pueden elegir en el listado.
     */
    public const POR_PAGINA = [10, 25, 50, 100];

    /**
     * Columnas del CSV, idénticas en importación y exportación.
     */
    public const COLUMNAS_CSV = ['nombre', 'modelo', 'clave_prod_serv', 'clave_unidad', 'objeto_imp', 'precio_proveedor', 'utilidad_porcentaje'];

    /**
     * Carpeta de las imágenes dentro del disco privado (local).
     */
    public const DIRECTORIO_IMAGENES = 'articulos';

    /**
     * Copia el proveedor del catálogo y calcula el costo y el precio de venta.
     * Es el único lugar donde se escriben, para el alta, la edición y la
     * importación. La copia no se desincroniza porque el proveedor de un
     * catálogo es fijo.
     */
    protected static function booted(): void
    {
        static::saving(function (Articulo $articulo) {
            if (! $articulo->isDirty(['catalogo_id', 'precio_proveedor', 'utilidad_porcentaje'])) {
                return;
            }

            // Consulta nueva: la relación cargada puede ser la del catálogo anterior.
            $catalogo = $articulo->catalogo()->firstOrFail();

            $articulo->proveedor_id = $catalogo->proveedor_id;
            $articulo->setRelation('catalogo', $catalogo);
            $articulo->recalcularPrecio($catalogo);
        });
    }

    /**
     * Escribe costo y precio de venta con el descuento y la utilidad del
     * catálogo (salvo que el artículo tenga utilidad propia). No guarda.
     */
    public function recalcularPrecio(Catalogo $catalogo): void
    {
        [$costo, $venta] = $this->calcularPrecio($catalogo->descuento, $catalogo->utilidad_porcentaje);

        $this->costo_con_descuento = $costo;
        $this->precio_unitario_sin_iva = $venta;
    }

    /**
     * Costo y precio de venta con un descuento y una utilidad de catálogo
     * dados, sin tocar el artículo; lo usa también el conteo de impacto.
     *
     * @return array{0: float, 1: float}
     */
    public function calcularPrecio(float|string $descuento, float|string $utilidadCatalogo): array
    {
        $costo = CalculadoraPrecioArticulo::costoConDescuento($this->precio_proveedor, $descuento);

        return [$costo, CalculadoraPrecioArticulo::precioVentaSinIva($costo, $this->utilidad_porcentaje ?? $utilidadCatalogo)];
    }

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
     * Incluye los catálogos eliminados, igual que proveedor().
     *
     * @return BelongsTo<Catalogo, $this>
     */
    public function catalogo(): BelongsTo
    {
        return $this->belongsTo(Catalogo::class)->withTrashed();
    }

    /**
     * Su fila en existencias, si está marcado (sin las quitadas, por el soft
     * delete).
     *
     * @return HasOne<Existencia, $this>
     */
    public function existencia(): HasOne
    {
        return $this->hasOne(Existencia::class);
    }

    /**
     * @return HasMany<MovimientoInventario, $this>
     */
    public function movimientosInventario(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class);
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
            'catalogo' => $consulta->orderBy(
                Catalogo::withTrashed()
                    ->select('nombre')
                    ->whereColumn('catalogos.id', 'articulos.catalogo_id'),
                $direccion
            ),
            'costo' => $consulta->orderBy('costo_con_descuento', $direccion),
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
        return Attribute::get(fn (): float => CalculadoraPrecioArticulo::precioConIva($this->precio_unitario_sin_iva, self::TASA_IVA));
    }

    /**
     * Porcentaje que realmente se aplicó: el propio o el del catálogo.
     *
     * @return Attribute<string, never>
     */
    protected function utilidadPorcentajeEfectivo(): Attribute
    {
        return Attribute::get(fn (): string => $this->utilidad_porcentaje ?? $this->catalogo->utilidad_porcentaje);
    }

    /**
     * Utilidad en pesos por pieza, sin IVA. No se guarda.
     *
     * @return Attribute<float, never>
     */
    protected function utilidad(): Attribute
    {
        return Attribute::get(fn (): float => CalculadoraPrecioArticulo::utilidad($this->precio_unitario_sin_iva, $this->costo_con_descuento));
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function tieneImagen(): Attribute
    {
        return Attribute::get(fn (): bool => $this->imagen_ruta !== null);
    }

    /**
     * Parte al azar del nombre del archivo ("{id}-{version}.webp"). Va en la
     * URL de la imagen para que un reemplazo cambie la dirección y el
     * navegador no muestre la foto anterior guardada en su caché.
     *
     * @return Attribute<string|null, never>
     */
    protected function imagenVersion(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->imagen_ruta === null
            ? null
            : Str::after(pathinfo($this->imagen_ruta, PATHINFO_FILENAME), '-'));
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
            'precio_proveedor' => 'decimal:2',
            'utilidad_porcentaje' => 'decimal:2',
            'costo_con_descuento' => 'decimal:2',
            'precio_unitario_sin_iva' => 'decimal:2',
        ];
    }
}
