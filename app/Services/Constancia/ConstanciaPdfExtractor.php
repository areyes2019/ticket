<?php

namespace App\Services\Constancia;

use Smalot\PdfParser\Document;
use Throwable;

/**
 * Estrategia B: pares "etiqueta: valor" del texto interno del PDF. Es exacto
 * (las letras se copian, no se adivinan); su límite es que dice lo que decía
 * el papel el día que se imprimió.
 */
class ConstanciaPdfExtractor
{
    public function __construct(private ParesPorPosicion $paresPorPosicion) {}

    /**
     * Devuelve una lista vacía si el PDF no tiene texto (un escaneo).
     *
     * @return list<array{0: string, 1: string}>
     */
    public function pares(Document $documento): array
    {
        $pares = [];

        foreach ($documento->getPages() as $pagina) {
            try {
                $datos = $pagina->getDataTm();
            } catch (Throwable) {
                continue;
            }

            $trozos = [];

            foreach ($datos as [$matriz, $texto]) {
                $trozos[] = [
                    'x' => (float) $matriz[4],
                    'y' => (float) $matriz[5],
                    'texto' => MapeadorCampos::aUtf8((string) $texto),
                ];
            }

            array_push($pares, ...$this->paresPorPosicion->pares($trozos));
        }

        return $pares;
    }
}
