<?php

namespace App\Services\Whatsapp;

use App\Models\WhatsappNumero;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Las únicas llamadas a la Graph API de Meta (041). Los archivos se suben a
 * Meta y se mandan por media id: nunca hay una URL pública del sistema.
 */
class ClienteMeta
{
    public function enviarTexto(WhatsappNumero $numero, string $waId, string $texto): string
    {
        return $this->enviar($numero, $waId, 'text', ['body' => $texto, 'preview_url' => false]);
    }

    public function enviarDocumento(WhatsappNumero $numero, string $waId, string $contenido, string $nombre, string $mime, ?string $pie = null): string
    {
        $mediaId = $this->subir($numero, $contenido, $nombre, $mime);

        return $this->enviar($numero, $waId, 'document', array_filter(['id' => $mediaId, 'filename' => $nombre, 'caption' => $pie]));
    }

    public function enviarImagen(WhatsappNumero $numero, string $waId, string $contenido, string $nombre, string $mime, ?string $pie = null): string
    {
        $mediaId = $this->subir($numero, $contenido, $nombre, $mime);

        return $this->enviar($numero, $waId, 'image', array_filter(['id' => $mediaId, 'caption' => $pie]));
    }

    /**
     * @param  list<string>  $variables  valores de {{1}}, {{2}}… del cuerpo
     */
    public function enviarPlantilla(WhatsappNumero $numero, string $waId, string $nombre, string $idioma, array $variables): string
    {
        $plantilla = ['name' => $nombre, 'language' => ['code' => $idioma]];

        if ($variables !== []) {
            $plantilla['components'] = [[
                'type' => 'body',
                'parameters' => array_map(fn (string $valor) => ['type' => 'text', 'text' => $valor], $variables),
            ]];
        }

        return $this->enviar($numero, $waId, 'template', $plantilla);
    }

    /**
     * Regla 23: palomitas azules y "escribiendo…". Si falla no pasa nada: es
     * cortesía, no parte del turno.
     */
    public function marcarLeidoYEscribiendo(WhatsappNumero $numero, string $wamid): void
    {
        try {
            $this->http($numero)->post($this->url($numero->phone_number_id.'/messages'), [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => $wamid,
                'typing_indicator' => ['type' => 'text'],
            ]);
        } catch (ConnectionException) {
            // Sin consecuencias.
        }
    }

    public function descargarMedia(WhatsappNumero $numero, string $mediaId): ArchivoDescargado
    {
        $datos = $this->llamar('consultar el archivo', fn () => $this->http($numero)->get($this->url($mediaId)))->json();

        $maximo = config('whatsapp.max_mb_archivo') * 1024 * 1024;

        if (($datos['file_size'] ?? 0) > $maximo) {
            throw new MetaException('El archivo pesa más de '.config('whatsapp.max_mb_archivo').' MB.');
        }

        $contenido = $this->llamar('descargar el archivo', fn () => $this->http($numero)->get($datos['url']))->body();

        return new ArchivoDescargado($contenido, $datos['mime_type'] ?? 'application/octet-stream');
    }

    /**
     * @return list<array{nombre: string, idioma: string, categoria: ?string, cuerpo: string, estado: string}>
     */
    public function plantillas(WhatsappNumero $numero): array
    {
        $respuesta = $this->llamar('leer las plantillas', fn () => $this->http($numero)->get($this->url($numero->waba_id.'/message_templates'), [
            'fields' => 'name,language,category,status,components',
            'limit' => 200,
        ]));

        return collect($respuesta->json('data') ?? [])->map(fn (array $p) => [
            'nombre' => $p['name'],
            'idioma' => $p['language'],
            'categoria' => $p['category'] ?? null,
            'cuerpo' => collect($p['components'] ?? [])->firstWhere('type', 'BODY')['text'] ?? '',
            'estado' => $p['status'] ?? 'UNKNOWN',
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function consultarNumero(WhatsappNumero $numero): array
    {
        return $this->llamar('consultar el número', fn () => $this->http($numero)->get($this->url($numero->phone_number_id), [
            'fields' => 'display_phone_number,verified_name,quality_rating,code_verification_status,name_status',
        ]))->json();
    }

    public function registrar(WhatsappNumero $numero, string $pin): void
    {
        $this->llamar('registrar el número', fn () => $this->http($numero)->post($this->url($numero->phone_number_id.'/register'), [
            'messaging_product' => 'whatsapp',
            'pin' => $pin,
        ]));
    }

    /**
     * @param  array<string, mixed>  $contenido
     */
    private function enviar(WhatsappNumero $numero, string $waId, string $tipo, array $contenido): string
    {
        $respuesta = $this->llamar('enviar el mensaje', fn () => $this->http($numero)->post($this->url($numero->phone_number_id.'/messages'), [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $waId,
            'type' => $tipo,
            $tipo => $contenido,
        ]));

        return (string) $respuesta->json('messages.0.id');
    }

    private function subir(WhatsappNumero $numero, string $contenido, string $nombre, string $mime): string
    {
        $respuesta = $this->llamar('subir el archivo', fn () => $this->http($numero)
            ->attach('file', $contenido, $nombre, ['Content-Type' => $mime])
            ->post($this->url($numero->phone_number_id.'/media'), [
                'messaging_product' => 'whatsapp',
                'type' => $mime,
            ]));

        return (string) $respuesta->json('id');
    }

    /**
     * @param  callable(): Response  $llamada
     */
    private function llamar(string $accion, callable $llamada): Response
    {
        try {
            $respuesta = $llamada();
        } catch (ConnectionException) {
            throw MetaException::sinConexion($accion);
        }

        if ($respuesta->failed()) {
            throw MetaException::desdeRespuesta($respuesta, $accion);
        }

        return $respuesta;
    }

    private function http(WhatsappNumero $numero): PendingRequest
    {
        return Http::withToken($numero->token)->acceptJson()->timeout(20);
    }

    private function url(string $ruta): string
    {
        return rtrim(config('whatsapp.graph_url'), '/').'/'.config('whatsapp.graph_version').'/'.$ruta;
    }
}
