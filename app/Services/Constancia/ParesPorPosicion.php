<?php

namespace App\Services\Constancia;

/**
 * Reconstruye los pares "etiqueta: valor" de un PDF a partir de los trozos de
 * texto y su coordenada, en vez del texto ya aplanado por la librería, que en
 * la constancia pierde los espacios dentro de un valor y junta las dos
 * columnas del domicilio en un solo renglón.
 *
 * Umbrales medidos en constancias reales: entre palabras hay ≈5 unidades y
 * entre columnas ≈180. El ancho de carácter se sobreestima a propósito:
 * pasarse junta trozos en la misma celda; quedarse corto partiría un valor.
 */
class ParesPorPosicion
{
    public const ANCHO_CARACTER = 7.0;

    public const SALTO_DE_COLUMNA = 30.0;

    /**
     * Diferencia vertical máxima para considerar dos trozos en el mismo renglón.
     */
    public const TOLERANCIA_RENGLON = 2.0;

    /**
     * Distancia vertical máxima para que un renglón continúe el valor anterior.
     */
    public const INTERLINEA_MAXIMA = 14.0;

    /**
     * @param  list<array{x: float, y: float, texto: string}>  $trozos
     * @return list<array{0: string, 1: string}>
     */
    public function pares(array $trozos): array
    {
        $pares = [];
        $anteriores = [];
        $yAnterior = null;

        foreach ($this->renglones($trozos) as $y => $celdas) {
            $cercano = $yAnterior !== null && abs($yAnterior - (float) $y) <= self::INTERLINEA_MAXIMA;
            $actuales = [];

            foreach ($this->unirValoresSueltos($celdas) as $celda) {
                $dosPuntos = mb_strpos($celda['texto'], ':');

                if ($dosPuntos === false) {
                    $indice = $cercano ? $this->continuacionDe($celda, $anteriores, $pares) : null;

                    if ($indice !== null) {
                        $pares[$indice][1] = trim($pares[$indice][1].' '.$celda['texto']);
                        $actuales[] = ['x' => $celda['x'], 'indice' => $indice];
                    } elseif ($this->esRegimen($celda['texto'])) {
                        // La tabla de regímenes de la constancia no lleva "Régimen:" en cada fila.
                        $pares[] = ['Régimen', $celda['texto']];
                    }

                    continue;
                }

                $pares[] = [
                    trim(mb_substr($celda['texto'], 0, $dosPuntos)),
                    trim(mb_substr($celda['texto'], $dosPuntos + 1)),
                ];
                $actuales[] = ['x' => $celda['x'], 'indice' => array_key_last($pares)];
            }

            $anteriores = $actuales;
            $yAnterior = (float) $y;
        }

        return $pares;
    }

    /**
     * Agrupa los trozos en renglones (de arriba abajo) y cada renglón en
     * celdas: los trozos contiguos se unen con el espacio que el PDF no
     * guardó; un hueco grande es el salto a la otra columna.
     *
     * @param  list<array{x: float, y: float, texto: string}>  $trozos
     * @return array<string, list<array{x: float, texto: string}>>
     */
    private function renglones(array $trozos): array
    {
        $trozos = array_values(array_filter($trozos, fn (array $trozo) => trim($trozo['texto']) !== ''));
        usort($trozos, fn (array $a, array $b) => $b['y'] <=> $a['y'] ?: $a['x'] <=> $b['x']);

        $grupos = [];

        foreach ($trozos as $trozo) {
            $ultimo = array_key_last($grupos);

            if ($ultimo !== null && abs($grupos[$ultimo]['y'] - $trozo['y']) <= self::TOLERANCIA_RENGLON) {
                $grupos[$ultimo]['trozos'][] = $trozo;
            } else {
                $grupos[] = ['y' => $trozo['y'], 'trozos' => [$trozo]];
            }
        }

        $renglones = [];

        foreach ($grupos as $grupo) {
            usort($grupo['trozos'], fn (array $a, array $b) => $a['x'] <=> $b['x']);
            $renglones[(string) $grupo['y']] = $this->celdas($grupo['trozos']);
        }

        return $renglones;
    }

    /**
     * @param  list<array{x: float, y: float, texto: string}>  $trozos
     * @return list<array{x: float, texto: string}>
     */
    private function celdas(array $trozos): array
    {
        $celdas = [];
        $fin = null;

        foreach ($trozos as $trozo) {
            $texto = trim($trozo['texto']);

            if ($fin === null || $trozo['x'] - $fin > self::SALTO_DE_COLUMNA) {
                $celdas[] = ['x' => $trozo['x'], 'texto' => $texto];
            } else {
                $celdas[array_key_last($celdas)]['texto'] .= ' '.$texto;
            }

            $fin = $trozo['x'] + mb_strlen($texto) * self::ANCHO_CARACTER;
        }

        return $celdas;
    }

    /**
     * "Código Postal:" en una celda y "96535" en la siguiente son un solo par.
     *
     * @param  list<array{x: float, texto: string}>  $celdas
     * @return list<array{x: float, texto: string}>
     */
    private function unirValoresSueltos(array $celdas): array
    {
        $unidas = [];

        foreach ($celdas as $celda) {
            $ultima = array_key_last($unidas);

            if ($ultima !== null && str_ends_with($unidas[$ultima]['texto'], ':') && ! str_contains($celda['texto'], ':')) {
                $unidas[$ultima]['texto'] .= ' '.$celda['texto'];
            } else {
                $unidas[] = $celda;
            }
        }

        return $unidas;
    }

    /**
     * Un renglón sin dos puntos, en mayúsculas y justo debajo de un par con
     * valor es la continuación de ese valor ("VERACRUZ DE IGNACIO DE LA" +
     * "LLAVE"). Se une al par del renglón anterior cuya columna le queda más
     * cerca por la izquierda.
     *
     * @param  array{x: float, texto: string}  $celda
     * @param  list<array{x: float, indice: int}>  $anteriores
     * @param  list<array{0: string, 1: string}>  $pares
     */
    private function continuacionDe(array $celda, array $anteriores, array $pares): ?int
    {
        if (mb_strtoupper($celda['texto']) !== $celda['texto'] || $this->esRegimen($celda['texto'])) {
            return null;
        }

        $elegido = null;

        foreach ($anteriores as $anterior) {
            if ($anterior['x'] <= $celda['x'] + self::SALTO_DE_COLUMNA && $pares[$anterior['indice']][1] !== '') {
                $elegido = $anterior['indice'];
            }
        }

        return $elegido;
    }

    private function esRegimen(string $texto): bool
    {
        $llave = MapeadorCampos::esqueleto($texto);

        // "Régimen" solo es el encabezado de la tabla; una fila trae la descripción.
        return str_starts_with($llave, 'regimen') && strlen($llave) > strlen('regimenes');
    }
}
