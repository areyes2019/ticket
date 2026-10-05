<?php

namespace App\Services\Imagenes;

use App\Services\Articulos\ImagenInvalida;
use GdImage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Toda imagen que sube el usuario (fotos de artículos de 010, diseños de
 * órdenes de trabajo de 022 y logos de los datos bancarios de 027): la
 * comprueba por contenido, la reduce, la regenera como WEBP con un nombre
 * propio en el disco privado y reemplaza la anterior del modelo (columna
 * imagen_ruta, salvo que se indique otra) sin dejar huecos.
 */
class GuardadorImagenWebp
{
    /**
     * Lado largo máximo de la imagen guardada, en puntos, si quien llama no
     * pide otro.
     */
    public const LADO_MAXIMO = 1200;

    public const CALIDAD_WEBP = 82;

    /**
     * Tamaño máximo de una imagen individual, en kilobytes.
     */
    public const TAMANO_MAXIMO_KB = 10240;

    private const TIPOS_ACEPTADOS = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /**
     * Si el contenido es un JPEG, PNG o WEBP que GD puede decodificar.
     */
    public function esImagen(string $contenido): bool
    {
        try {
            $this->abrir($contenido);

            return true;
        } catch (ImagenInvalida) {
            return false;
        }
    }

    /**
     * Guarda la imagen como la del modelo. Orden seguro: primero el archivo
     * nuevo, luego la columna y al final se borra el anterior, para que la
     * columna nunca apunte a un archivo inexistente.
     *
     * @throws ImagenInvalida
     */
    public function guardar(Model $modelo, string $directorio, string $contenido, int $ladoMaximo = self::LADO_MAXIMO, string $columna = 'imagen_ruta'): void
    {
        $webp = $this->convertir($contenido, $ladoMaximo);
        $disco = Storage::disk('local');
        $ruta = $directorio.'/'.$modelo->getKey().'-'.Str::lower(Str::random(8)).'.webp';

        if (! $disco->put($ruta, $webp)) {
            throw new RuntimeException("No se pudo escribir la imagen {$ruta}.");
        }

        $anterior = $modelo->{$columna};

        try {
            $modelo->{$columna} = $ruta;
            $modelo->saveQuietly();
        } catch (Throwable $excepcion) {
            $modelo->{$columna} = $anterior;
            $disco->delete($ruta);

            throw $excepcion;
        }

        if ($anterior !== null) {
            $disco->delete($anterior);
        }
    }

    /**
     * Deja al modelo sin imagen y borra el archivo.
     */
    public function quitar(Model $modelo, string $columna = 'imagen_ruta'): void
    {
        $anterior = $modelo->{$columna};

        if ($anterior === null) {
            return;
        }

        $modelo->{$columna} = null;
        $modelo->saveQuietly();

        Storage::disk('local')->delete($anterior);
    }

    /**
     * Regenera la imagen: orientada según su EXIF, con el lado largo en
     * $ladoMaximo como máximo (nunca se amplía) y con su transparencia.
     *
     * @throws ImagenInvalida
     */
    private function convertir(string $contenido, int $ladoMaximo): string
    {
        $origen = $this->abrir($contenido);
        $destino = null;

        try {
            $ancho = imagesx($origen);
            $alto = imagesy($origen);
            $escala = min(1, $ladoMaximo / max($ancho, $alto));
            $nuevoAncho = max(1, (int) round($ancho * $escala));
            $nuevoAlto = max(1, (int) round($alto * $escala));

            // imagescale no permite apagar la mezcla y deja fondo negro en los recortes transparentes.
            $destino = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
            imagealphablending($destino, false);
            imagesavealpha($destino, true);
            imagefill($destino, 0, 0, imagecolorallocatealpha($destino, 0, 0, 0, 127));
            imagecopyresampled($destino, $origen, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);

            ob_start();
            imagewebp($destino, null, self::CALIDAD_WEBP);

            return (string) ob_get_clean();
        } finally {
            // Una tanda procesa hasta 20 imágenes en la misma petición.
            $origen = null;
            $destino = null;
        }
    }

    /**
     * @throws ImagenInvalida
     */
    private function abrir(string $contenido): GdImage
    {
        $informacion = @getimagesizefromstring($contenido);

        if ($informacion === false || ! in_array($informacion[2], self::TIPOS_ACEPTADOS, true)) {
            throw new ImagenInvalida;
        }

        $imagen = @imagecreatefromstring($contenido);

        if ($imagen === false) {
            throw new ImagenInvalida;
        }

        imagepalettetotruecolor($imagen);

        return $informacion[2] === IMAGETYPE_JPEG ? $this->orientar($imagen, $contenido) : $imagen;
    }

    /**
     * Las fotos de celular guardan la rotación en el EXIF en lugar de en los
     * puntos; sin esto saldrían acostadas.
     */
    private function orientar(GdImage $imagen, string $contenido): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $imagen;
        }

        $flujo = fopen('php://memory', 'r+b');
        fwrite($flujo, $contenido);
        rewind($flujo);
        $exif = @exif_read_data($flujo);
        fclose($flujo);

        $angulo = match ($exif['Orientation'] ?? null) {
            3 => 180,
            6 => 270,
            8 => 90,
            default => 0,
        };

        if ($angulo === 0) {
            return $imagen;
        }

        $rotada = imagerotate($imagen, $angulo, 0);

        return $rotada === false ? $imagen : $rotada;
    }
}
