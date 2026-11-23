<?php

namespace App\Services\Whatsapp;

use App\Enums\AgenteWhatsapp;
use App\Enums\EstadoConversacion;
use App\Models\Emisor;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Services\Whatsapp\Claude\ClaudeException;
use App\Services\Whatsapp\Claude\ClienteClaude;
use App\Services\Whatsapp\Herramientas\Herramienta;
use App\Services\Whatsapp\Herramientas\ResultadoHerramienta;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Un turno del agente (041, reglas 20 a 27): junta los mensajes pendientes del
 * cliente, revisa los candados que no necesitan a Claude, corre el ciclo de
 * herramientas y contesta.
 */
class OrquestadorTurno
{
    public const PALABRAS_HUMANO = ['asesor', 'humano', 'persona', 'agente humano'];

    public const AVISO_AUDIO = 'Por ahora no puedo escuchar audios ni ver videos. ¿Me lo escribes, por favor?';

    public const AVISO_LIMITE = 'Un asesor continuará la conversación contigo en breve.';

    public const AVISO_FALLA = 'Tuve un problema para responderte. Un asesor te contesta en breve.';

    /**
     * Tipos de imagen que Claude lee.
     */
    private const IMAGENES_CLAUDE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private ClienteClaude $claude,
        private ClienteMeta $meta,
        private EnviadorWhatsapp $enviador,
        private EstadoConversaciones $estados,
        private CatalogoHerramientas $catalogo,
        private RegistroUso $uso,
        private Resumidor $resumidor,
    ) {}

    /**
     * @return bool si quedaron mensajes del cliente por atender (otro turno)
     */
    public function correr(WhatsappConversacion $conversacion): bool
    {
        $conversacion->loadMissing(['numero', 'user', 'cliente']);

        if ($conversacion->estado !== EstadoConversacion::Agente) {
            return false;
        }

        $pendientes = $this->pendientes($conversacion);

        if ($pendientes->isEmpty()) {
            return false;
        }

        $this->descargarArchivos($conversacion, $pendientes);

        if ($this->pidioHumano($pendientes)) {
            $this->atender($pendientes);
            $this->estados->pasarAHumano($conversacion, 'El cliente pidió un asesor');

            return false;
        }

        $legibles = $pendientes->filter(fn (WhatsappMensaje $m) => in_array($m->tipo, WhatsappMensaje::TIPOS_LEGIBLES, true));

        // Regla 18: audios y videos no llaman a Claude; el aviso, una vez al día.
        if ($legibles->count() < $pendientes->count()) {
            $this->avisarAudio($conversacion);
        }

        if ($legibles->isEmpty()) {
            $this->atender($pendientes);

            return false;
        }

        $numero = $conversacion->numero;

        if (! $numero->agenteActivo($conversacion->agente_actual)) {
            $this->atender($pendientes);
            $this->estados->pasarAHumano($conversacion, 'El agente de '.mb_strtolower($conversacion->agente_actual->etiqueta()).' está apagado');

            return false;
        }

        // Regla 74.
        if ($this->uso->topeAlcanzado($conversacion->user, $numero->tope_mensual_usd)) {
            $this->atender($pendientes);
            $this->estados->pasarAHumano($conversacion, 'Tope de gasto mensual');

            return false;
        }

        // Adición 6: cuenta el turno, no el mensaje.
        if ($this->rebasaLimite($conversacion)) {
            $this->atender($pendientes);
            $this->avisoUnico($conversacion, self::AVISO_LIMITE);
            $this->estados->pasarAHumano($conversacion, 'Límite de mensajes', avisar: false);

            return false;
        }

        $this->meta->marcarLeidoYEscribiendo($numero, (string) $pendientes->last()->wamid);

        $turno = new ContextoTurno($conversacion);
        $this->conversar($turno, $pendientes);

        $this->atender($pendientes);
        $conversacion->forceFill(['ventas_validadas' => array_values(array_unique($turno->ventasValidadas))])->save();

        if ($turno->pasoAHumano) {
            $this->estados->pasarAHumano($conversacion, $conversacion->motivo_humano ?: 'Lo pidió el agente');
        }

        $this->actualizarResumen($conversacion);

        return $conversacion->estado === EstadoConversacion::Agente && $this->pendientes($conversacion)->isNotEmpty();
    }

    /**
     * El ciclo de herramientas (regla 52), con la transferencia entre agentes
     * en el mismo turno (regla 2).
     *
     * @param  Collection<int, WhatsappMensaje>  $pendientes
     */
    private function conversar(ContextoTurno $turno, Collection $pendientes): void
    {
        $conversacion = $turno->conversacion;
        $usos = 0;
        $transferencias = 0;
        $maximo = config('whatsapp.max_herramientas_turno');

        $agente = $conversacion->agente_actual;
        $herramientas = $this->catalogo->de($agente);
        $mensajes = $this->mensajes($conversacion, $pendientes);

        while (true) {
            try {
                $respuesta = $this->claude->crear($this->system($conversacion, $agente), $mensajes, array_values(array_map(self::definicion(...), $herramientas)));
            } catch (ClaudeException $error) {
                // Sin nada hecho todavía, el trabajo se reintenta (regla 25).
                if ($error->pasajera && $usos === 0) {
                    throw $error;
                }

                $this->falla($turno, $error->getMessage());

                return;
            }

            $this->uso->registrar($conversacion->user, $conversacion, $respuesta);

            if ($respuesta->rechazada()) {
                $turno->pasoAHumano = true;
                $conversacion->motivo_humano = 'El agente no pudo responder (rechazo de Claude)';

                return;
            }

            if (! $respuesta->pideHerramientas()) {
                $this->responder($turno, $respuesta->texto());

                return;
            }

            $resultados = [];

            foreach ($respuesta->usosDeHerramienta() as $uso) {
                $usos++;
                $resultado = $this->ejecutar($turno, $herramientas[$uso['name']] ?? null, $uso);
                $resultados[] = [
                    'type' => 'tool_result',
                    'toolUseID' => $uso['id'],
                    'content' => $resultado->json(),
                    'isError' => $resultado->error,
                ];
            }

            if ($turno->pasoAHumano) {
                return;
            }

            if ($turno->transferirA !== null && $transferencias < 2) {
                $transferencias++;
                $agente = $turno->transferirA;
                $turno->transferirA = null;
                $conversacion->forceFill(['agente_actual' => $agente])->save();
                EnviadorWhatsapp::nota($conversacion, 'Transferida al agente de '.mb_strtolower($agente->etiqueta()).'.');

                // El otro agente empieza limpio, con el mismo historial.
                $herramientas = $this->catalogo->de($agente);
                $mensajes = $this->mensajes($conversacion, $pendientes, transferidaDe: $agente === AgenteWhatsapp::Ventas ? AgenteWhatsapp::Facturacion : AgenteWhatsapp::Ventas);

                continue;
            }

            // Regla 26.
            if ($usos >= $maximo) {
                $turno->pasoAHumano = true;
                $conversacion->motivo_humano = 'El agente no terminó de resolver';

                return;
            }

            $mensajes[] = ['role' => 'assistant', 'content' => $respuesta->contenido];
            $mensajes[] = ['role' => 'user', 'content' => $resultados];
        }
    }

    /**
     * @param  array{id: string, name: string, input: array<string, mixed>}  $uso
     */
    private function ejecutar(ContextoTurno $turno, ?Herramienta $herramienta, array $uso): ResultadoHerramienta
    {
        if ($herramienta === null) {
            $resultado = ResultadoHerramienta::error('Esa herramienta no existe para este agente.');
        } else {
            try {
                $resultado = $herramienta->ejecutar($turno, $uso['input'] ?? []);
            } catch (Throwable $error) {
                report($error);
                $resultado = ResultadoHerramienta::error('Falló una operación interna. Dile al cliente que un asesor le ayuda y usa pasar_a_humano.', 'error interno: '.Str::limit($error->getMessage(), 120));
            }
        }

        // Regla 54: queda en la bandeja, plegado.
        $turno->conversacion->mensajes()->create([
            'direccion' => WhatsappMensaje::INTERNO,
            'autor' => $turno->conversacion->agente_actual->value,
            'tipo' => 'herramienta',
            'texto' => $resultado->resumen,
            'datos' => [
                'nombre' => $uso['name'],
                'entrada' => $uso['input'] ?? [],
                'resultado' => Str::limit($resultado->json(), 2000),
                'error' => $resultado->error,
            ],
            'respondido' => true,
            'enviado_en' => now(),
        ]);

        return $resultado;
    }

    /**
     * Manda la respuesta final en hasta 5 mensajes (regla 26).
     */
    private function responder(ContextoTurno $turno, string $texto): void
    {
        $partes = self::partir($texto, config('whatsapp.max_mensajes_turno') - min($turno->documentosEnviados, 2));

        if ($partes === []) {
            return;
        }

        $conversacion = $turno->conversacion;

        try {
            foreach ($partes as $parte) {
                $this->enviador->texto($conversacion, $parte, $conversacion->agente_actual->value);
            }
        } catch (VentanaCerrada) {
            $turno->pasoAHumano = true;
            $conversacion->motivo_humano = 'Se cerró la ventana de 24 h antes de responder';
        } catch (MetaException $error) {
            $this->falla($turno, $error->getMessage());
        }
    }

    private function falla(ContextoTurno $turno, string $detalle): void
    {
        Log::warning('Falla en un turno de WhatsApp.', ['conversacion' => $turno->conversacion->id, 'error' => $detalle]);

        $this->avisoUnico($turno->conversacion, self::AVISO_FALLA);
        $turno->pasoAHumano = true;
        $turno->conversacion->motivo_humano = 'Falla técnica: '.Str::limit($detalle, 150);
    }

    /**
     * Un aviso del sistema que cuenta como el aviso de humano (regla 30).
     */
    private function avisoUnico(WhatsappConversacion $conversacion, string $texto): void
    {
        if ($conversacion->aviso_humano_enviado || ! $conversacion->ventanaAbierta()) {
            return;
        }

        try {
            $this->enviador->texto($conversacion, $texto, 'sistema');
        } catch (MetaException $error) {
            Log::warning('No salió un aviso de WhatsApp.', ['conversacion' => $conversacion->id, 'error' => $error->getMessage()]);
        }

        $conversacion->forceFill(['aviso_humano_enviado' => true])->save();
    }

    private function avisarAudio(WhatsappConversacion $conversacion): void
    {
        $hoy = now(config('app.zona_negocio'))->toDateString();

        if ($conversacion->aviso_audio_el?->toDateString() === $hoy || ! $conversacion->ventanaAbierta()) {
            return;
        }

        try {
            $this->enviador->texto($conversacion, self::AVISO_AUDIO, 'sistema');
        } catch (MetaException $error) {
            Log::warning('No salió el aviso de audio.', ['conversacion' => $conversacion->id, 'error' => $error->getMessage()]);
        }

        $conversacion->forceFill(['aviso_audio_el' => $hoy])->save();
    }

    /**
     * Regla 29, sin llamar a Claude.
     *
     * @param  Collection<int, WhatsappMensaje>  $pendientes
     */
    private function pidioHumano(Collection $pendientes): bool
    {
        return $pendientes->contains(fn (WhatsappMensaje $m) => $m->tipo === 'texto' && self::esPalabraHumano((string) $m->texto));
    }

    public static function esPalabraHumano(string $texto): bool
    {
        $limpio = trim((string) preg_replace('/[^a-z ]+/', ' ', Str::lower(Str::ascii($texto))));
        $limpio = (string) preg_replace('/\s+/', ' ', $limpio);

        foreach (self::PALABRAS_HUMANO as $palabra) {
            if ($limpio === $palabra || str_starts_with($limpio, $palabra.' ')) {
                return true;
            }
        }

        return false;
    }

    private function rebasaLimite(WhatsappConversacion $conversacion): bool
    {
        if ($conversacion->sin_limite) {
            return false;
        }

        $numero = $conversacion->numero;
        $hora = 'wa-hora:'.$conversacion->id;
        $dia = 'wa-dia:'.$conversacion->id;

        if (RateLimiter::tooManyAttempts($hora, $numero->limite_hora) || RateLimiter::tooManyAttempts($dia, $numero->limite_dia)) {
            return true;
        }

        RateLimiter::hit($hora, 3600);
        RateLimiter::hit($dia, 86400);

        return false;
    }

    /**
     * @return Collection<int, WhatsappMensaje>
     */
    private function pendientes(WhatsappConversacion $conversacion): Collection
    {
        return $conversacion->mensajes()->where('direccion', WhatsappMensaje::ENTRANTE)->where('respondido', false)->get();
    }

    /**
     * @param  Collection<int, WhatsappMensaje>  $pendientes
     */
    private function atender(Collection $pendientes): void
    {
        WhatsappMensaje::whereIn('id', $pendientes->modelKeys())->update(['respondido' => true]);
    }

    /**
     * Reglas 17 y 19: los archivos se bajan aquí, no en el webhook.
     *
     * @param  Collection<int, WhatsappMensaje>  $pendientes
     */
    private function descargarArchivos(WhatsappConversacion $conversacion, Collection $pendientes): void
    {
        foreach ($pendientes as $mensaje) {
            $mediaId = $mensaje->datos['media_id'] ?? null;

            if ($mediaId === null || $mensaje->archivo_ruta !== null) {
                continue;
            }

            try {
                $archivo = $this->meta->descargarMedia($conversacion->numero, $mediaId);
            } catch (MetaException $error) {
                if ($error->pasajera) {
                    throw $error;
                }

                $mensaje->forceFill(['tipo' => 'otro', 'texto' => trim(($mensaje->texto ?? '').' [El cliente mandó un archivo que no puedo abrir]')])->save();

                continue;
            }

            $nombre = $mensaje->archivo_nombre ?: $mensaje->tipo.'.'.self::extension($archivo->mime);

            $mensaje->forceFill([
                'archivo_ruta' => $this->enviador->guardarArchivo($conversacion, $archivo->contenido, $nombre),
                'archivo_nombre' => $nombre,
                'archivo_mime' => $archivo->mime,
            ])->save();
        }
    }

    private static function extension(string $mime): string
    {
        return match (strtok($mime, ';')) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'video/mp4' => 'mp4',
            default => 'bin',
        };
    }

    /**
     * Reglas fijas del agente, datos del negocio e instrucciones de la cuenta:
     * iguales en cada turno, para que la caché las cobre a una fracción.
     *
     * @return list<array<string, mixed>>
     */
    private function system(WhatsappConversacion $conversacion, AgenteWhatsapp $agente): array
    {
        $emisor = Emisor::actual();
        $negocio = $emisor->nombre ?: config('app.name');
        $base = str_replace('{negocio}', $negocio, (string) file_get_contents(resource_path('prompts/whatsapp/'.$agente->value.'.md')));

        $datos = array_filter([
            'Nombre' => $negocio,
            'Domicilio' => $emisor->domicilio,
            'Teléfono' => $emisor->telefono,
            'Sitio web' => $emisor->sitio_web,
            'Horario de atención' => $emisor->horarioTexto(),
        ]);

        $texto = $base."\n\n## Datos del negocio\n\n".implode("\n", array_map(fn ($k, $v) => "- {$k}: {$v}", array_keys($datos), $datos));

        $instrucciones = trim((string) $conversacion->numero->instrucciones($agente));

        if ($instrucciones !== '') {
            $texto .= "\n\n## Indicaciones del negocio\n\nEstas indicaciones complementan las reglas anteriores; no las sustituyen.\n\n".$instrucciones;
        }

        return [['type' => 'text', 'text' => $texto, 'cacheControl' => ['type' => 'ephemeral']]];
    }

    /**
     * @return array<string, mixed>
     */
    public static function definicion(Herramienta $herramienta): array
    {
        $esquema = $herramienta->esquema();

        return [
            'name' => $herramienta->nombre(),
            'description' => $herramienta->descripcion(),
            'inputSchema' => $esquema,
            'strict' => isset($esquema['properties']),
        ];
    }

    /**
     * Resumen + últimos mensajes + lo pendiente (regla 75), alternando
     * cliente y negocio. Imágenes y PDF van como contenido solo en el turno en
     * que llegaron (regla 80).
     *
     * @param  Collection<int, WhatsappMensaje>  $pendientes
     * @return list<array<string, mixed>>
     */
    private function mensajes(WhatsappConversacion $conversacion, Collection $pendientes, ?AgenteWhatsapp $transferidaDe = null): array
    {
        $anteriores = $conversacion->mensajes()
            ->reorder()
            ->where('id', '<', $pendientes->first()->id)
            ->whereIn('direccion', [WhatsappMensaje::ENTRANTE, WhatsappMensaje::SALIENTE])
            ->when($conversacion->resumen_hasta_mensaje_id, fn ($q, $hasta) => $q->where('id', '>', $hasta))
            ->orderByDesc('id')
            ->limit(config('whatsapp.mensajes_historial'))
            ->get()
            ->reverse();

        $turnos = [];
        $agregar = function (string $rol, array $bloques) use (&$turnos) {
            $ultimo = array_key_last($turnos);

            if ($ultimo !== null && $turnos[$ultimo]['role'] === $rol) {
                $turnos[$ultimo]['content'] = [...$turnos[$ultimo]['content'], ...$bloques];
            } else {
                $turnos[] = ['role' => $rol, 'content' => $bloques];
            }
        };

        if ($conversacion->resumen) {
            $agregar('user', [['type' => 'text', 'text' => '[Resumen de la conversación anterior, escrito por el sistema] '.$conversacion->resumen]]);
        }

        foreach ($anteriores as $mensaje) {
            $agregar($mensaje->esDelCliente() ? 'user' : 'assistant', [['type' => 'text', 'text' => self::comoTexto($mensaje)]]);
        }

        // La API pide que empiece el cliente.
        if ($turnos !== [] && $turnos[0]['role'] === 'assistant') {
            array_unshift($turnos, ['role' => 'user', 'content' => [['type' => 'text', 'text' => '[Inicio de la conversación]']]]);
        }

        $bloques = [];

        foreach ($pendientes as $mensaje) {
            $bloques = [...$bloques, ...$this->bloquesDelTurno($mensaje)];
        }

        $bloques[] = ['type' => 'text', 'text' => $this->contexto($conversacion, $transferidaDe)];
        $agregar('user', $bloques);

        return $turnos;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bloquesDelTurno(WhatsappMensaje $mensaje): array
    {
        $texto = self::comoTexto($mensaje);

        if ($mensaje->archivo_ruta === null || ! in_array($mensaje->tipo, ['imagen', 'documento'], true)) {
            return [['type' => 'text', 'text' => $texto]];
        }

        $mime = strtok((string) $mensaje->archivo_mime, ';');
        $contenido = base64_encode(Storage::disk('local')->get($mensaje->archivo_ruta));

        return match (true) {
            in_array($mime, self::IMAGENES_CLAUDE, true) => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mime, 'data' => $contenido]],
                ['type' => 'text', 'text' => $texto],
            ],
            $mime === 'application/pdf' => [
                ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => $contenido]],
                ['type' => 'text', 'text' => $texto],
            ],
            default => [['type' => 'text', 'text' => $texto]],
        };
    }

    public static function comoTexto(WhatsappMensaje $mensaje): string
    {
        $texto = trim((string) $mensaje->texto);

        $marca = match ($mensaje->tipo) {
            'imagen' => '[Imagen "'.($mensaje->archivo_nombre ?? 'imagen').'", archivo_id '.$mensaje->id.']',
            'documento' => '[Documento "'.($mensaje->archivo_nombre ?? 'documento').'", archivo_id '.$mensaje->id.']',
            'audio' => '[Nota de voz que no puedo escuchar]',
            'video' => '[Video que no puedo ver]',
            default => null,
        };

        $todo = trim(($marca ?? '').' '.$texto);

        return $todo === '' ? '[Mensaje vacío]' : $todo;
    }

    private function contexto(WhatsappConversacion $conversacion, ?AgenteWhatsapp $transferidaDe): string
    {
        $ahora = now(config('app.zona_negocio'));
        $cliente = $conversacion->cliente;

        $lineas = [
            '[Contexto del sistema; no lo escribió el cliente]',
            'Fecha y hora: '.$ahora->translatedFormat('l j \d\e F \d\e Y, H:i').'.',
            'Teléfono del cliente: '.$conversacion->telefonoLegible().'.',
            $cliente !== null && $cliente->rfc !== 'XAXX010101000'
                ? 'Cliente registrado: '.($cliente->nombre_contacto ?: $cliente->razon_social).((float) $cliente->descuento_permanente > 0 ? ' (tiene descuento permanente de cliente).' : '.')
                : 'El teléfono no es de un cliente registrado.',
        ];

        if ($conversacion->nombre_perfil) {
            $lineas[] = 'Nombre de perfil de WhatsApp: '.$conversacion->nombre_perfil.'.';
        }

        if ($transferidaDe !== null) {
            $lineas[] = 'El agente de '.mb_strtolower($transferidaDe->etiqueta()).' te acaba de pasar esta conversación: contesta tú el último mensaje del cliente.';
        }

        return implode("\n", $lineas);
    }

    /**
     * Párrafos agrupados en mensajes de menos de 1 000 caracteres; si sobran,
     * el último lleva el resto.
     *
     * @return list<string>
     */
    public static function partir(string $texto, int $maximo): array
    {
        $texto = trim($texto);

        if ($texto === '') {
            return [];
        }

        $partes = [];
        $actual = '';

        foreach (preg_split('/\n{2,}/', $texto) ?: [] as $parrafo) {
            if ($actual !== '' && mb_strlen($actual) + mb_strlen($parrafo) + 2 > 1000) {
                $partes[] = $actual;
                $actual = $parrafo;
            } else {
                $actual = $actual === '' ? $parrafo : $actual."\n\n".$parrafo;
            }
        }

        $partes[] = $actual;
        $maximo = max($maximo, 1);

        if (count($partes) > $maximo) {
            $partes = [...array_slice($partes, 0, $maximo - 1), implode("\n\n", array_slice($partes, $maximo - 1))];
        }

        return $partes;
    }

    private function actualizarResumen(WhatsappConversacion $conversacion): void
    {
        try {
            $this->resumidor->actualizarSiHaceFalta($conversacion);
        } catch (Throwable $error) {
            Log::info('No se actualizó el resumen de WhatsApp.', ['conversacion' => $conversacion->id, 'error' => $error->getMessage()]);
        }
    }
}
