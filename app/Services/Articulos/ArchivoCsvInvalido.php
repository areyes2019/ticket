<?php

namespace App\Services\Articulos;

use RuntimeException;

/**
 * El archivo no se puede procesar como un todo (vacío o sin las columnas
 * esperadas); los errores de una fila no usan esta excepción.
 */
class ArchivoCsvInvalido extends RuntimeException {}
