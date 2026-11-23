<?php

namespace App\Jobs;

use App\Models\WhatsappConversacion;
use App\Services\Whatsapp\EnviadorWhatsapp;
use App\Services\Whatsapp\EstadoConversaciones;
use App\Services\Whatsapp\OrquestadorTurno;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Un turno del agente en segundo plano (041, adición 4). Hay a lo más uno
 * en cola por conversación (los mensajes que llegan mientras espera se juntan,
 * regla 21) y uno corriendo, por el candado de turno (regla 22).
 */
class ProcesarConversacion implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * Las esperas por el candado cuentan como intento; los errores, solo 3.
     */
    public int $tries = 6;

    public int $maxExceptions = 3;

    public int $timeout = 50;

    public int $uniqueFor = 120;

    public function __construct(public int $conversacionId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function uniqueId(): string
    {
        return (string) $this->conversacionId;
    }

    public function handle(OrquestadorTurno $orquestador): void
    {
        $conversacion = WhatsappConversacion::find($this->conversacionId);

        if ($conversacion === null) {
            return;
        }

        $candado = Cache::lock('wa-turno:'.$conversacion->id, 120);

        if (! $candado->get()) {
            // Otro turno sigue corriendo: este vuelve en un momento.
            $this->release(20);

            return;
        }

        try {
            $quedan = $orquestador->correr($conversacion);
        } finally {
            $candado->release();
        }

        if ($quedan) {
            self::dispatch($conversacion->id)->delay(now()->addSeconds(2));
        }
    }

    /**
     * Respuesta al momento (041, regla 24 corregida): el webhook ya le contestó
     * a Meta y, en la misma visita, corre el turno sin esperar al cron. El
     * trabajo en cola queda de respaldo: si esto falla o no alcanza el candado,
     * el cron lo atiende y reintenta como siempre. Nunca lanza.
     */
    public static function alMomento(int $conversacionId): void
    {
        $conversacion = WhatsappConversacion::find($conversacionId);

        if ($conversacion === null) {
            return;
        }

        $candado = Cache::lock('wa-turno:'.$conversacion->id, 120);

        if (! $candado->get()) {
            return;
        }

        ignore_user_abort(true);
        @set_time_limit(150);

        try {
            app(OrquestadorTurno::class)->correr($conversacion);
        } catch (Throwable $error) {
            // El trabajo en cola lo reintenta en el siguiente minuto.
            Log::info('El turno al momento falló; queda para la cola.', ['conversacion' => $conversacion->id, 'error' => $error->getMessage()]);
        } finally {
            $candado->release();
        }
    }

    /**
     * Regla 25: a la tercera, pasa a humano con aviso al cliente.
     */
    public function failed(?Throwable $error): void
    {
        $conversacion = WhatsappConversacion::find($this->conversacionId);

        if ($conversacion === null) {
            return;
        }

        Log::error('Turno de WhatsApp fallido.', ['conversacion' => $conversacion->id, 'error' => $error?->getMessage()]);

        try {
            $estados = app(EstadoConversaciones::class);
            $conversacion->mensajes()->where('direccion', 'entrante')->where('respondido', false)->update(['respondido' => true]);

            if ($conversacion->ventanaAbierta() && ! $conversacion->aviso_humano_enviado) {
                app(EnviadorWhatsapp::class)->texto($conversacion, OrquestadorTurno::AVISO_FALLA, 'sistema');
                $conversacion->forceFill(['aviso_humano_enviado' => true])->save();
            }

            $estados->pasarAHumano($conversacion, 'Falla técnica: '.mb_substr((string) $error?->getMessage(), 0, 150), avisar: false);
        } catch (Throwable $otro) {
            report($otro);
        }
    }
}
