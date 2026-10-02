<?php

namespace App\Services\Articulos;

use App\Models\Articulo;
use App\Services\Imagenes\GuardadorImagenWebp;

/**
 * Puerta única de entrada de toda imagen de artículo, venga del formulario o
 * de la carga masiva. La conversión y el reemplazo seguro viven en
 * GuardadorImagenWebp, que comparte con las órdenes de trabajo (022).
 */
class ProcesadorImagenArticulo
{
    public const LADO_MAXIMO = GuardadorImagenWebp::LADO_MAXIMO;

    public const CALIDAD_WEBP = GuardadorImagenWebp::CALIDAD_WEBP;

    public const TAMANO_MAXIMO_KB = GuardadorImagenWebp::TAMANO_MAXIMO_KB;

    public function __construct(private readonly GuardadorImagenWebp $guardador) {}

    /**
     * Si el contenido es un JPEG, PNG o WEBP que GD puede decodificar.
     */
    public function esImagen(string $contenido): bool
    {
        return $this->guardador->esImagen($contenido);
    }

    /**
     * @throws ImagenInvalida
     */
    public function guardar(Articulo $articulo, string $contenido): void
    {
        $this->guardador->guardar($articulo, Articulo::DIRECTORIO_IMAGENES, $contenido);
    }

    /**
     * Deja al artículo sin imagen y borra el archivo.
     */
    public function quitar(Articulo $articulo): void
    {
        $this->guardador->quitar($articulo);
    }
}
