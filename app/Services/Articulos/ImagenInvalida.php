<?php

namespace App\Services\Articulos;

use RuntimeException;

/**
 * El contenido no es una imagen JPEG, PNG o WEBP que GD pueda leer.
 */
class ImagenInvalida extends RuntimeException {}
