<?php

namespace App\Services\Whatsapp;

use App\Enums\EstadoConversacion;
use App\Jobs\ProcesarConversacion;
use App\Models\Cliente;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappNumero;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lo que hace el webhook (041, reglas 12 a 17 y adiciones 1, 3 y 4): revisa
 * la firma, guarda mensajes y estados, y encola un turno por conversación.
 * Nada de esto llama a Claude, al SAT ni descarga archivos.
 */
class ReceptorWebhook
{
    public function __construct(private EstadoConversaciones $estados) {}

    /**
     * Adición 1: HMAC-SHA256 del cuerpo tal como llegó, con la clave secreta
     * de alguna app configurada.
     */
    public static function firmaValida(string $cuerpo, ?string $cabecera): bool
    {
        if ($cabecera === null || ! str_starts_with($cabecera, 'sha256=')) {
            return false;
        }

        $firma = substr($cabecera, 7);

        foreach (WhatsappNumero::all() as $numero) {
            if (hash_equals(hash_hmac('sha256', $cuerpo, $numero->app_secret), $firma)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $aviso
     */
    public function procesar(array $aviso): void
    {
        foreach ($aviso['entry'] ?? [] as $entrada) {
            foreach ($entrada['changes'] ?? [] as $cambio) {
                $valor = $cambio['value'] ?? [];
                $numero = WhatsappNumero::where('phone_number_id', $valor['metadata']['phone_number_id'] ?? '')->first();

                // Regla 13: otro número (por ejemplo, el del otro sistema).
                if ($numero === null) {
                    continue;
                }

                $perfiles = collect($valor['contacts'] ?? [])->mapWithKeys(fn (array $c) => [$c['wa_id'] ?? '' => $c['profile']['name'] ?? null]);

                foreach ($valor['messages'] ?? [] as $mensaje) {
                    $this->mensaje($numero, $mensaje, $perfiles->get($mensaje['from'] ?? '') ?? null);
                }

                foreach ($valor['statuses'] ?? [] as $estado) {
                    $this->estado($estado);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $mensaje
     */
    private function mensaje(WhatsappNumero $numero, array $mensaje, ?string $perfil): void
    {
        $waId = (string) ($mensaje['from'] ?? '');
        $wamid = (string) ($mensaje['id'] ?? '');

        if ($waId === '' || $wamid === '') {
            return;
        }

        $encolar = DB::transaction(function () use ($numero, $mensaje, $perfil, $waId, $wamid) {
            $conversacion = $this->conversacion($numero, $waId, $perfil);
            $enviadoEn = isset($mensaje['timestamp']) ? CarbonImmutable::createFromTimestamp((int) $mensaje['timestamp']) : CarbonImmutable::now();

            // Adición 3: un wamid repetido no se inserta ni se procesa.
            $insertado = WhatsappMensaje::query()->insertOrIgnore([
                'whatsapp_conversacion_id' => $conversacion->id,
                'wamid' => $wamid,
                'direccion' => WhatsappMensaje::ENTRANTE,
                'autor' => 'cliente',
                'respondido' => false,
                'enviado_en' => $enviadoEn,
                'created_at' => now(),
                'updated_at' => now(),
                ...self::contenido($mensaje),
            ]);

            if ($insertado === 0) {
                return null;
            }

            if ($conversacion->estado === EstadoConversacion::Cerrada) {
                $this->estados->reabrir($conversacion);
            }

            $conversacion->forceFill([
                'ultimo_mensaje_cliente_en' => now(),
                'ultimo_mensaje_en' => now(),
                'pendientes_humano' => $conversacion->enManosDeHumano() ? $conversacion->pendientes_humano + 1 : 0,
            ])->save();

            return $conversacion;
        });

        if ($encolar !== null) {
            ProcesarConversacion::dispatch($encolar->id);
        }
    }

    private function conversacion(WhatsappNumero $numero, string $waId, ?string $perfil): WhatsappConversacion
    {
        $conversacion = WhatsappConversacion::where('whatsapp_numero_id', $numero->id)->where('wa_id', $waId)->lockForUpdate()->first();

        if ($conversacion === null) {
            $conversacion = new WhatsappConversacion;
            $conversacion->forceFill([
                'whatsapp_numero_id' => $numero->id,
                'user_id' => $numero->user_id,
                'wa_id' => $waId,
                'telefono_10' => Telefono::diezDigitos($waId) ?? substr($waId, -10),
                'cliente_id' => self::clienteUnico($numero->user_id, $waId)?->id,
            ]);
        }

        if ($perfil !== null && $perfil !== '') {
            $conversacion->nombre_perfil = Str::limit($perfil, 250, '');
        }

        $conversacion->save();

        return $conversacion;
    }

    /**
     * Regla 77: se liga solo si el teléfono coincide con un único cliente.
     */
    public static function clienteUnico(int $userId, string $telefono): ?Cliente
    {
        $diez = Telefono::diezDigitos($telefono);

        if ($diez === null) {
            return null;
        }

        $clientes = Cliente::where('user_id', $userId)->where('telefono_10', $diez)->limit(2)->get();

        return $clientes->count() === 1 ? $clientes->first() : null;
    }

    /**
     * Regla 17: tipo y texto de cada mensaje. Los archivos se descargan en el
     * turno, no aquí; el media id queda en datos.
     *
     * @param  array<string, mixed>  $mensaje
     * @return array{tipo: string, texto: ?string, archivo_nombre: ?string, archivo_mime: ?string, datos: ?string}
     */
    public static function contenido(array $mensaje): array
    {
        $tipo = $mensaje['type'] ?? 'otro';
        $media = fn (string $clave) => [
            'archivo_nombre' => $mensaje[$clave]['filename'] ?? null,
            'archivo_mime' => $mensaje[$clave]['mime_type'] ?? null,
            'datos' => json_encode(['media_id' => $mensaje[$clave]['id'] ?? null]),
        ];
        $vacio = ['archivo_nombre' => null, 'archivo_mime' => null, 'datos' => null];

        return match ($tipo) {
            'text' => ['tipo' => 'texto', 'texto' => $mensaje['text']['body'] ?? '', ...$vacio],
            'image' => ['tipo' => 'imagen', 'texto' => $mensaje['image']['caption'] ?? null, ...$media('image')],
            'document' => ['tipo' => 'documento', 'texto' => $mensaje['document']['caption'] ?? null, ...$media('document')],
            'audio' => ['tipo' => 'audio', 'texto' => null, ...$media('audio')],
            'video' => ['tipo' => 'video', 'texto' => $mensaje['video']['caption'] ?? null, ...$media('video')],
            'sticker' => ['tipo' => 'otro', 'texto' => '[Sticker]', ...$vacio],
            'button' => ['tipo' => 'texto', 'texto' => $mensaje['button']['text'] ?? '', ...$vacio],
            'interactive' => ['tipo' => 'texto', 'texto' => $mensaje['interactive']['button_reply']['title'] ?? $mensaje['interactive']['list_reply']['title'] ?? '', ...$vacio],
            'location' => ['tipo' => 'texto', 'texto' => '[Ubicación: '.trim(implode(', ', array_filter([
                $mensaje['location']['name'] ?? null,
                $mensaje['location']['address'] ?? null,
                isset($mensaje['location']['latitude']) ? $mensaje['location']['latitude'].', '.$mensaje['location']['longitude'] : null,
            ]))).']', ...$vacio],
            'contacts' => ['tipo' => 'texto', 'texto' => '[Contacto: '.implode(', ', array_map(
                fn (array $c) => trim(($c['name']['formatted_name'] ?? '').' '.($c['phones'][0]['phone'] ?? '')),
                $mensaje['contacts'] ?? [],
            )).']', ...$vacio],
            default => ['tipo' => 'otro', 'texto' => '[Mensaje de tipo '.$tipo.']', ...$vacio],
        };
    }

    /**
     * Regla 16.
     *
     * @param  array<string, mixed>  $estado
     */
    private function estado(array $estado): void
    {
        $nuevo = match ($estado['status'] ?? null) {
            'sent' => 'enviado',
            'delivered' => 'entregado',
            'read' => 'leido',
            'failed' => 'fallido',
            default => null,
        };

        if ($nuevo === null || ! isset($estado['id'])) {
            return;
        }

        $mensaje = WhatsappMensaje::where('wamid', $estado['id'])->first();

        if ($mensaje === null) {
            return;
        }

        // Un "entregado" que llega tarde no borra un "leído".
        $orden = ['enviado' => 1, 'entregado' => 2, 'leido' => 3, 'fallido' => 4];

        if (($orden[$mensaje->estado_envio] ?? 0) >= $orden[$nuevo]) {
            return;
        }

        $error = $estado['errors'][0] ?? null;

        $mensaje->forceFill([
            'estado_envio' => $nuevo,
            'error_envio' => $error === null ? null : Str::limit($error['error_data']['details'] ?? $error['message'] ?? $error['title'] ?? 'sin detalle', 250),
        ])->save();
    }
}
