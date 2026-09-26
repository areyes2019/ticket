<?php

namespace App\Services\Constancia;

use App\Enums\FuenteConstancia;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Document;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Analiza una Constancia de Situación Fiscal: lee el QR, consulta al SAT y,
 * si el SAT no contesta, extrae los datos del texto del PDF (Estrategia B).
 *
 * Nada se escribe en disco: el archivo se lee desde el temporal de PHP.
 */
class ConstanciaFiscalService
{
    public const CLAVE_CAIDA = 'csf:sat:caido';

    public function __construct(
        private QrLector $qrLector,
        private SatHtmlExtractor $satHtmlExtractor,
        private ConstanciaPdfExtractor $pdfExtractor,
        private MapeadorCampos $mapeador,
    ) {}

    /**
     * @throws ConstanciaException
     */
    public function analizar(?UploadedFile $archivo, ?string $qrUrl): ResultadoConstancia
    {
        $contenido = $archivo?->get();
        $documento = $contenido !== null && $archivo->getMimeType() === 'application/pdf' ? $this->leerPdf($contenido) : null;

        $url = $this->direccionDelQr($qrUrl, $documento, $documento === null ? $contenido : null);

        if (! IdentidadQr::esOficial($url, config('services.sat.dominio'))) {
            throw ConstanciaException::qrNoOficial();
        }

        $identidad = IdentidadQr::desdeUrl($url) ?? throw ConstanciaException::qrNoLegible();

        $html = $this->consultarSat($identidad);
        $camposSat = $html !== null ? $this->mapeador->agrupar($this->satHtmlExtractor->pares($html)) : [];
        $datosSat = $this->mapeador->datos($camposSat, $identidad->rfc);

        if ($html !== null) {
            $this->registrarRespuestaIncompleta($html, $datosSat['data']);
        }

        $camposPdf = $documento !== null && $this->mapeador->faltantes($datosSat['data']) !== []
            ? $this->mapeador->agrupar($this->pdfExtractor->pares($documento))
            : [];

        // Lo que el SAT sí devolvió manda; el documento solo completa lo que falte.
        $final = $this->mapeador->datos($camposSat + $camposPdf, $identidad->rfc);

        return $this->resultado($html !== null, $datosSat['data'], $final);
    }

    /**
     * @param  array<string, mixed>  $dataSat
     * @param  array{data: array<string, mixed>, advertencias: list<string>}  $final
     */
    private function resultado(bool $satContesto, array $dataSat, array $final): ResultadoConstancia
    {
        $data = $final['data'];
        $advertencias = $final['advertencias'];
        $satAporto = count($dataSat) > 1;

        if (count($data) === 1) {
            return new ResultadoConstancia(FuenteConstancia::QrRfc, $data, $advertencias, $satContesto
                ? 'El SAT respondió, pero no se pudieron leer sus datos. El RFC se tomó del código QR y es correcto; captura el resto de los datos a mano.'
                : 'No se pudo consultar al SAT y el documento no trae texto legible. El RFC se tomó del código QR y es correcto; captura el resto de los datos a mano.');
        }

        $faltantes = $this->mapeador->faltantes($data);

        if ($faltantes !== []) {
            $advertencias[] = 'No se encontraron en la constancia: '.implode(', ', $faltantes).'.';
        }

        if (! $satAporto) {
            return new ResultadoConstancia(FuenteConstancia::PdfTexto, $data, $advertencias,
                'Estos datos se tomaron de la constancia que subiste y no se confirmaron con el SAT; verifica que sea reciente y que el domicilio siga vigente.');
        }

        $delDocumento = $this->mapeador->faltantes($dataSat) !== []
            ? array_diff($this->mapeador->faltantes($dataSat), $faltantes)
            : [];

        if ($delDocumento !== []) {
            $advertencias[] = 'El SAT no devolvió '.implode(', ', $delDocumento).'; se tomó del documento que subiste.';
        }

        return new ResultadoConstancia(FuenteConstancia::SatQrDirect, $data, $advertencias, null);
    }

    /**
     * Orden: la dirección que leyó el navegador, luego el QR guardado dentro
     * del PDF y al final el de la foto.
     *
     * @throws ConstanciaException
     */
    private function direccionDelQr(?string $qrUrl, ?Document $documento, ?string $imagen): string
    {
        $url = $qrUrl
            ?? ($documento !== null ? $this->qrLector->leerDePdf($documento) : null)
            ?? ($imagen !== null ? $this->qrLector->leerDeImagen($imagen) : null);

        return $url ?? throw ConstanciaException::qrNoLegible();
    }

    private function leerPdf(string $contenido): ?Document
    {
        try {
            return (new Parser)->parseContent($contenido);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Devuelve el HTML del validador, o null si el SAT no contestó.
     *
     * Solo una caída de verdad (red, tiempo agotado, código de error) abre el
     * circuito: durante ese tiempo nadie espera los segundos del timeout. Una
     * respuesta que no se entiende del todo no es una caída.
     */
    private function consultarSat(IdentidadQr $identidad): ?string
    {
        $clave = 'csf:sat:'.sha1($identidad->idCif.'_'.$identidad->rfc);
        $enCache = Cache::get($clave);

        if (is_string($enCache)) {
            return $enCache;
        }

        if (Cache::has(self::CLAVE_CAIDA)) {
            return null;
        }

        $segundos = config('services.sat.timeout');

        try {
            $respuesta = Http::timeout($segundos)
                ->connectTimeout($segundos)
                ->get($identidad->urlValidador(config('services.sat.validador_url')));
        } catch (ConnectionException) {
            $respuesta = null;
        }

        if ($respuesta === null || $respuesta->failed()) {
            Cache::put(self::CLAVE_CAIDA, true, config('services.sat.caida_segundos'));

            return null;
        }

        Cache::put($clave, $respuesta->body(), now()->addHours(config('services.sat.cache_horas')));

        return $respuesta->body();
    }

    /**
     * Una respuesta incompleta del SAT delata un cambio de etiquetas; se deja
     * constancia en el log (solo etiquetas, nunca datos del contribuyente).
     *
     * @param  array<string, mixed>  $dataSat
     */
    private function registrarRespuestaIncompleta(string $html, array $dataSat): void
    {
        $faltantes = $this->mapeador->faltantes($dataSat);

        if ($faltantes === []) {
            return;
        }

        $sinReconocer = collect($this->satHtmlExtractor->pares($html))
            ->map(fn (array $par) => trim($par[0]))
            ->filter(fn (string $etiqueta) => $this->mapeador->campo($etiqueta) === null && mb_strlen($etiqueta) <= 60)
            ->unique()
            ->values()
            ->all();

        Log::warning('Constancia: respuesta del SAT incompleta', [
            'faltantes' => $faltantes,
            'etiquetas_sin_reconocer' => $sinReconocer,
        ]);
    }
}
