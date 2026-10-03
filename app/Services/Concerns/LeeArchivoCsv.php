<?php

namespace App\Services\Concerns;

trait LeeArchivoCsv
{
    /**
     * Devuelve el archivo como flujo UTF-8 sin BOM. Acepta UTF-8 (con o sin
     * BOM) y Windows-1252, detectado por el contenido: es lo que guarda Excel
     * en español según la opción que elija el usuario.
     *
     * @return resource
     */
    private function abrirComoUtf8(string $ruta)
    {
        $contenido = (string) file_get_contents($ruta);

        if (str_starts_with($contenido, "\xEF\xBB\xBF")) {
            $contenido = substr($contenido, 3);
        }

        if (! mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }

        $flujo = fopen('php://temp', 'r+');
        fwrite($flujo, $contenido);
        rewind($flujo);

        return $flujo;
    }

    /**
     * @param  list<string|null>  $celdas
     */
    private function estaVacia(array $celdas): bool
    {
        return array_filter($celdas, fn (?string $celda) => trim((string) $celda) !== '') === [];
    }
}
