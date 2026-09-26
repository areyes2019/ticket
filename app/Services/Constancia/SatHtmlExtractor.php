<?php

namespace App\Services\Constancia;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Recoge los pares "etiqueta: valor" de la página del validador del SAT.
 *
 * No usa selectores atados a la maquetación: toma todas las filas de dos
 * celdas y, como respaldo, las líneas "Etiqueta: valor" del texto. Un rediseño
 * que mueva las cajas sigue funcionando; uno que renombre etiquetas se
 * resuelve con un alias en MapeadorCampos.
 */
class SatHtmlExtractor
{
    /**
     * @return list<array{0: string, 1: string}>
     */
    public function pares(string $html): array
    {
        $html = MapeadorCampos::aUtf8($html);

        return [...$this->paresDeFilas($html), ...$this->paresDeTexto($html)];
    }

    /**
     * El validador antepone una cabecera XML al DOCTYPE. Si se deja adivinar,
     * DomCrawler la toma por XML, ningún <tr> coincide y devuelve cero filas
     * sin error; por eso se carga explícitamente como HTML.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function paresDeFilas(string $html): array
    {
        $crawler = new Crawler;
        $crawler->addHtmlContent((string) preg_replace('/^\s*<\?xml[^>]*>/i', '', $html), 'UTF-8');

        $pares = [];

        foreach ($crawler->filter('tr') as $fila) {
            $celdas = (new Crawler($fila))->filter('td, th');

            if ($celdas->count() >= 2) {
                $pares[] = [rtrim(trim($celdas->eq(0)->text('')), ':'), trim($celdas->eq(1)->text(''))];
            }
        }

        return $pares;
    }

    /**
     * Respaldo: cada línea "Etiqueta: valor" del texto de la página. El RFC
     * viene dentro de una frase ("El RFC: XXX, tiene asociada la siguiente
     * información") y se captura aparte para contrastarlo con el del QR.
     *
     * Alrededor de los dos puntos solo se admiten espacios horizontales: con
     * \s* una etiqueta sin valor se quedaría con el renglón siguiente.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function paresDeTexto(string $html): array
    {
        $texto = strip_tags((string) preg_replace('/<(br|\/?(tr|td|th|p|div|li|h\d|table))[^>]*>/i', "\n", $html));
        $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $pares = [];

        if (preg_match('/\bRFC:[ \t]*([A-ZÑ&0-9]{12,13})\b/u', $texto, $rfc)) {
            $pares[] = ['RFC', $rfc[1]];
        }

        preg_match_all('/^[ \t]*([^:\n]{2,80}?)[ \t]*:[ \t]*(\S[^\n]*)$/mu', $texto, $lineas, PREG_SET_ORDER);

        foreach ($lineas as $linea) {
            $pares[] = [$linea[1], $linea[2]];
        }

        return $pares;
    }
}
