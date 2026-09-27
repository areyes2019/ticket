<?php

namespace App\Services\Articulos;

use RuntimeException;

/**
 * El .zip no se puede procesar como un todo (dañado, con carpetas o demasiado
 * grande al descomprimirse); no se guarda ninguna de sus imágenes.
 */
class ArchivoZipInvalido extends RuntimeException {}
