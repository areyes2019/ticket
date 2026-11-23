<?php

namespace App\Services\Whatsapp;

use App\Enums\AgenteWhatsapp;

/**
 * Las indicaciones de un agente guardadas por secciones (045). Arma el texto
 * que recibe el agente siempre igual para los mismos datos, para no perder la
 * caché de instrucciones de Claude (adición 6).
 */
class TextoIndicaciones
{
    public const MAX_SECCION = 1000;

    public const MAX_PREGUNTA = 200;

    public const MAX_RESPUESTA = 500;

    public const MAX_PREGUNTAS = 30;

    public const GUIA_PREGUNTAS = 'Usa estas respuestas como base; puedes decirlas con tus palabras.';

    /**
     * Texto para el agente, sin el encabezado "## Indicaciones del negocio"
     * (lo pone OrquestadorTurno). Null si no hay nada (regla 20).
     *
     * @param  array<string, mixed>  $caja
     */
    public static function armar(AgenteWhatsapp $agente, array $caja): ?string
    {
        $partes = [];

        foreach ($agente->secciones() as $clave => $seccion) {
            if ($clave === 'preguntas') {
                $pares = self::pares($caja['preguntas'] ?? []);

                if ($pares === []) {
                    continue;
                }

                $lista = array_map(
                    fn (array $par) => '- P: '.$par['pregunta']."\n  R: ".str_replace("\n", "\n  ", $par['respuesta']),
                    $pares,
                );

                $partes[] = '### '.$seccion['titulo']."\n\n".self::GUIA_PREGUNTAS."\n\n".implode("\n", $lista);

                continue;
            }

            $texto = self::limpiar($caja[$clave] ?? null);

            if ($texto !== null) {
                $partes[] = '### '.$seccion['titulo']."\n\n".$texto;
            }
        }

        return $partes === [] ? null : implode("\n\n", $partes);
    }

    /**
     * La caja que se guarda: solo las claves del agente, textos limpios y
     * únicamente los pares completos (reglas 12, 22 y 37).
     *
     * @param  array<string, mixed>|null  $entrada
     * @return array<string, mixed>
     */
    public static function normalizar(AgenteWhatsapp $agente, ?array $entrada): array
    {
        $caja = [];

        foreach (array_keys($agente->secciones()) as $clave) {
            $caja[$clave] = $clave === 'preguntas'
                ? self::pares($entrada['preguntas'] ?? [])
                : self::limpiar($entrada[$clave] ?? null);
        }

        return $caja;
    }

    /**
     * Quita los pares totalmente vacíos y vuelve a numerar (reglas 12 y 32).
     *
     * @return list<array{pregunta: ?string, respuesta: ?string}>
     */
    public static function sinVacios(mixed $preguntas): array
    {
        if (! is_array($preguntas)) {
            return [];
        }

        $pares = [];

        foreach ($preguntas as $par) {
            $pregunta = is_array($par) && is_string($par['pregunta'] ?? null) ? $par['pregunta'] : null;
            $respuesta = is_array($par) && is_string($par['respuesta'] ?? null) ? $par['respuesta'] : null;

            if (self::limpiar($pregunta) === null && self::limpiar($respuesta) === null) {
                continue;
            }

            $pares[] = ['pregunta' => $pregunta, 'respuesta' => $respuesta];
        }

        return $pares;
    }

    /**
     * Regla 37: sin espacios en las orillas y con saltos de línea \n.
     */
    public static function limpiar(mixed $texto): ?string
    {
        if (! is_string($texto)) {
            return null;
        }

        $texto = trim(str_replace(["\r\n", "\r"], "\n", $texto));

        return $texto === '' ? null : $texto;
    }

    /**
     * Total de caracteres de las secciones, preguntas y respuestas (regla 18).
     *
     * @param  array<string, mixed>  $caja
     */
    public static function caracteres(AgenteWhatsapp $agente, array $caja): int
    {
        $total = 0;

        foreach (array_keys($agente->seccionesTexto()) as $clave) {
            $total += mb_strlen((string) ($caja[$clave] ?? ''));
        }

        foreach ((array) ($caja['preguntas'] ?? []) as $par) {
            $total += mb_strlen((string) ($par['pregunta'] ?? '')) + mb_strlen((string) ($par['respuesta'] ?? ''));
        }

        return $total;
    }

    /**
     * @return list<array{pregunta: string, respuesta: string}>
     */
    private static function pares(mixed $preguntas): array
    {
        $pares = [];

        foreach (is_array($preguntas) ? $preguntas : [] as $par) {
            $pregunta = self::limpiar($par['pregunta'] ?? null);
            $respuesta = self::limpiar($par['respuesta'] ?? null);

            if ($pregunta !== null && $respuesta !== null) {
                $pares[] = ['pregunta' => $pregunta, 'respuesta' => $respuesta];
            }
        }

        return $pares;
    }
}
