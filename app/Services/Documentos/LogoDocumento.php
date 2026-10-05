<?php

namespace App\Services\Documentos;

use Illuminate\Support\Facades\Log;

/**
 * Logo fijo de los PDF (026). Se incrusta como data URI: ninguna vista de PDF
 * referencia archivos por URL ni por ruta relativa. Las medidas se calculan
 * aquí en milímetros porque dompdf respeta a medias max-width/max-height.
 * Sin archivo, el PDF sale sin logo y queda un warning: nunca falla por esto.
 */
class LogoDocumento
{
    public const RUTA = 'img/marca/logo-sello-pronto-600.png';

    public const ANCHO_MAXIMO_MM = 50;

    public const ALTO_MAXIMO_MM = 30;

    /** @var array{uri: string|null, ancho: int, alto: int}|null */
    private ?array $leido = null;

    public function __construct(private ?string $archivo = null)
    {
        $this->archivo ??= public_path(self::RUTA);
    }

    public function dataUri(): ?string
    {
        return $this->leer()['uri'];
    }

    /**
     * El logo ajustado a la caja sin deformarse.
     *
     * @return array{ancho_mm: float, alto_mm: float}
     */
    public function medidas(): array
    {
        return self::ajustar($this->leer()['ancho'], $this->leer()['alto']);
    }

    /**
     * @return array{ancho_mm: float, alto_mm: float}
     */
    public static function ajustar(int $ancho, int $alto): array
    {
        if ($ancho <= 0 || $alto <= 0) {
            return ['ancho_mm' => 0.0, 'alto_mm' => 0.0];
        }

        $escala = min(self::ANCHO_MAXIMO_MM / $ancho, self::ALTO_MAXIMO_MM / $alto);

        return ['ancho_mm' => round($ancho * $escala, 1), 'alto_mm' => round($alto * $escala, 1)];
    }

    /**
     * Memoizado: el envío por correo genera el PDF varias veces por petición.
     *
     * @return array{uri: string|null, ancho: int, alto: int}
     */
    private function leer(): array
    {
        if ($this->leido !== null) {
            return $this->leido;
        }

        $contenido = is_readable($this->archivo) ? file_get_contents($this->archivo) : false;
        $info = $contenido !== false ? @getimagesizefromstring($contenido) : false;

        if ($info === false) {
            Log::warning('No se pudo leer el logo de los PDF; se generan sin él.', ['archivo' => $this->archivo]);

            return $this->leido = ['uri' => null, 'ancho' => 0, 'alto' => 0];
        }

        return $this->leido = [
            'uri' => 'data:'.$info['mime'].';base64,'.base64_encode($contenido),
            'ancho' => $info[0],
            'alto' => $info[1],
        ];
    }
}
