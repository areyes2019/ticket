<?php

namespace App\Services\Constancia;

use PhpCfdi\Rfc\Rfc;

/**
 * Identidad del contribuyente tomada del parámetro D3 del QR de la constancia
 * ("idCIF_RFC"). Es el único dato del trámite pensado para que lo lea una
 * máquina, así que el RFC que sale de aquí no puede traer una letra confundida.
 */
final readonly class IdentidadQr
{
    public function __construct(
        public string $idCif,
        public string $rfc,
    ) {}

    /**
     * La dirección debe ser https y su host sat.gob.mx o un subdominio suyo.
     * "sat.gob.mx.otro.com" no pasa: se compara el final exacto del host.
     */
    public static function esOficial(string $url, string $dominio): bool
    {
        $partes = parse_url(trim($url));

        if ($partes === false || strtolower($partes['scheme'] ?? '') !== 'https' || ! isset($partes['host'])) {
            return false;
        }

        $host = strtolower($partes['host']);

        return $host === $dominio || str_ends_with($host, '.'.$dominio);
    }

    /**
     * Lee el D3 de la dirección del validador. Devuelve null si no tiene la
     * forma idCIF_RFC o si el RFC no es válido (por ejemplo, el QR del sello
     * digital, que también trae un D3 pero con otro contenido).
     */
    public static function desdeUrl(string $url): ?self
    {
        parse_str((string) parse_url(trim($url), PHP_URL_QUERY), $parametros);

        $d3 = $parametros['D3'] ?? null;

        if (! is_string($d3) || ! preg_match('/^(\d+)_([A-ZÑ&0-9]{12,13})$/u', strtoupper(trim($d3)), $coincidencia)) {
            return null;
        }

        $rfc = Rfc::parseOrNull($coincidencia[2]);

        return $rfc === null ? null : new self($coincidencia[1], $rfc->getRfc());
    }

    /**
     * Dirección canónica del validador para este contribuyente.
     */
    public function urlValidador(string $base): string
    {
        return $base.'?'.http_build_query(['D1' => 10, 'D2' => 1, 'D3' => $this->idCif.'_'.$this->rfc]);
    }
}
