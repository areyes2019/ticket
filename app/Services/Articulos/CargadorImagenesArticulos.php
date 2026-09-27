<?php

namespace App\Services\Articulos;

use App\Models\Articulo;
use App\Models\Catalogo;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Carga masiva de imágenes: asocia cada archivo al artículo del catálogo cuyo
 * modelo coincide con el nombre del archivo. Los archivos que no se pueden
 * asociar se reportan uno por uno sin detener la carga.
 */
class CargadorImagenesArticulos
{
    /**
     * Tope del contenido descomprimido de un .zip, en bytes.
     */
    public const EXPANSION_MAXIMA_ZIP = 400 * 1024 * 1024;

    /**
     * @param  int  $expansionMaxima  tope del contenido descomprimido de un .zip, en bytes
     */
    public function __construct(
        private ProcesadorImagenArticulo $procesador,
        private int $expansionMaxima = self::EXPANSION_MAXIMA_ZIP,
    ) {}

    /**
     * @param  list<UploadedFile>  $archivos
     * @return array{asociadas: int, errores: list<array{archivo: string, motivo: string}>}
     */
    public function cargarArchivos(Catalogo $catalogo, array $archivos): array
    {
        return $this->procesar($catalogo, array_map(fn (UploadedFile $archivo) => [
            'nombre' => $archivo->getClientOriginalName(),
            'tamano' => (int) $archivo->getSize(),
            'contenido' => fn (): string => (string) file_get_contents($archivo->getRealPath()),
        ], $archivos));
    }

    /**
     * @return array{asociadas: int, errores: list<array{archivo: string, motivo: string}>}
     *
     * @throws ArchivoZipInvalido
     */
    public function cargarZip(Catalogo $catalogo, string $ruta): array
    {
        $zip = new ZipArchive;

        if ($zip->open($ruta, ZipArchive::RDONLY) !== true) {
            throw new ArchivoZipInvalido('No se pudo abrir el .zip; puede estar dañado.');
        }

        try {
            return $this->procesar($catalogo, $this->entradasZip($zip));
        } finally {
            $zip->close();
        }
    }

    /**
     * Revisa el índice completo antes de leer nada: si trae carpetas o se
     * expande demasiado se rechaza entero, para que el resultado no dependa de
     * cómo quedó armado el comprimido. De cada entrada solo se usa el nombre,
     * nunca la ruta escrita dentro.
     *
     * @return list<array{nombre: string, tamano: int, contenido: Closure(): string}>
     *
     * @throws ArchivoZipInvalido
     */
    private function entradasZip(ZipArchive $zip): array
    {
        $entradas = [];
        $total = 0;

        for ($indice = 0; $indice < $zip->numFiles; $indice++) {
            $datos = $zip->statIndex($indice);

            if ($datos === false) {
                throw new ArchivoZipInvalido('No se pudo leer el .zip; puede estar dañado.');
            }

            if (str_contains($datos['name'], '/') || str_contains($datos['name'], '\\')) {
                throw new ArchivoZipInvalido('El .zip tiene carpetas dentro. Comprime seleccionando los archivos, no la carpeta.');
            }

            $total += $datos['size'];
            $entradas[] = [
                'nombre' => $datos['name'],
                'tamano' => (int) $datos['size'],
                'contenido' => fn (): string => (string) $zip->getFromIndex($indice),
            ];
        }

        if ($total > $this->expansionMaxima) {
            throw new ArchivoZipInvalido('El contenido del .zip pesa más de 400 MB descomprimido; divídelo en varios.');
        }

        return $entradas;
    }

    /**
     * @param  list<array{nombre: string, tamano: int, contenido: Closure(): string}>  $archivos
     * @return array{asociadas: int, errores: list<array{archivo: string, motivo: string}>}
     */
    private function procesar(Catalogo $catalogo, array $archivos): array
    {
        $articulosPorModelo = $catalogo->articulos()
            ->where('user_id', $catalogo->user_id)
            ->get()
            ->groupBy(fn (Articulo $articulo) => self::normalizar($articulo->modelo));

        /** @var array<int, string> $asignados id del artículo => archivo que lo ganó */
        $asignados = [];
        $errores = [];

        foreach ($archivos as $archivo) {
            $destino = $this->destino($archivo, $articulosPorModelo->get(self::normalizar(pathinfo($archivo['nombre'], PATHINFO_FILENAME))));

            if (is_string($destino)) {
                $errores[] = ['archivo' => $archivo['nombre'], 'motivo' => $destino];

                continue;
            }

            try {
                $this->procesador->guardar($destino, $archivo['contenido']());
            } catch (ImagenInvalida) {
                $errores[] = ['archivo' => $archivo['nombre'], 'motivo' => 'no es una imagen JPG, PNG ni WEBP'];

                continue;
            }

            // Gana el último: el anterior ya quedó reemplazado en el disco.
            if (isset($asignados[$destino->id])) {
                $errores[] = ['archivo' => $asignados[$destino->id], 'motivo' => 'otro archivo de esta carga se asignó al mismo artículo'];
            }

            $asignados[$destino->id] = $archivo['nombre'];
        }

        return ['asociadas' => count($asignados), 'errores' => $errores];
    }

    /**
     * El artículo al que va el archivo, o el motivo concreto por el que no va
     * a ninguno.
     *
     * @param  array{nombre: string, tamano: int, contenido: Closure(): string}  $archivo
     * @param  iterable<Articulo>|null  $candidatos
     */
    private function destino(array $archivo, ?iterable $candidatos): Articulo|string
    {
        $modelo = pathinfo($archivo['nombre'], PATHINFO_FILENAME);
        $candidatos = collect($candidatos ?? []);

        return match (true) {
            $candidatos->isEmpty() => "no hay ningún artículo con modelo \"{$modelo}\" en este catálogo",
            $candidatos->count() > 1 => "hay {$candidatos->count()} artículos con modelo \"{$modelo}\" en este catálogo",
            $archivo['tamano'] > ProcesadorImagenArticulo::TAMANO_MAXIMO_KB * 1024 => 'pesa más de 10 MB',
            default => $candidatos->first(),
        };
    }

    /**
     * Minúsculas, sin acentos y con espacios, guiones y guiones bajos
     * colapsados a un solo espacio: "A_1234" y "a 1234" son el mismo modelo.
     */
    public static function normalizar(string $texto): string
    {
        return Str::of($texto)->ascii()->lower()->replaceMatches('/[\s\-_]+/', ' ')->trim()->toString();
    }
}
