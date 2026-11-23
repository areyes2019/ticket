<?php

namespace App\Services\Whatsapp;

use App\Enums\AgenteWhatsapp;
use App\Enums\EstadoConversacion;
use App\Models\WhatsappConversacion;
use Illuminate\Support\Facades\Log;

/**
 * Cambios de estado de una conversación (041, reglas 28 a 33): pasar a
 * humano, reactivar el agente, cerrar y reabrir.
 */
class EstadoConversaciones
{
    public function __construct(private EnviadorWhatsapp $enviador) {}

    /**
     * Regla 30: el cliente recibe el aviso una sola vez y solo si la ventana
     * sigue abierta. Si quien tomó la conversación fue un usuario, no hay aviso.
     */
    public function pasarAHumano(WhatsappConversacion $conversacion, string $motivo, bool $avisar = true): void
    {
        $yaEstaba = $conversacion->enManosDeHumano();

        $conversacion->forceFill([
            'estado' => EstadoConversacion::Humano,
            'motivo_humano' => $motivo,
            'pendientes_humano' => $conversacion->mensajes()->where('direccion', 'entrante')->where('respondido', false)->count(),
        ])->save();

        if (! $yaEstaba) {
            EnviadorWhatsapp::nota($conversacion, 'Pasó a humano: '.$motivo);
        }

        if ($avisar && ! $conversacion->aviso_humano_enviado && $conversacion->ventanaAbierta()) {
            try {
                $this->enviador->texto($conversacion, $conversacion->numero->avisoHumano(), 'sistema');
            } catch (MetaException $error) {
                Log::warning('No salió el aviso de pase a humano.', ['conversacion' => $conversacion->id, 'error' => $error->getMessage()]);
            }

            $conversacion->forceFill(['aviso_humano_enviado' => true])->save();
        }
    }

    /**
     * Regla 58: el usuario contestó; lo pendiente queda atendido.
     */
    public function tomarPorUsuario(WhatsappConversacion $conversacion): void
    {
        if (! $conversacion->enManosDeHumano()) {
            $this->pasarAHumano($conversacion, 'Lo atiende un usuario', avisar: false);
        }

        $this->marcarAtendidos($conversacion);
    }

    /**
     * Regla 32: el agente espera al siguiente mensaje del cliente.
     */
    public function reactivar(WhatsappConversacion $conversacion): void
    {
        $this->marcarAtendidos($conversacion);

        $conversacion->forceFill([
            'estado' => EstadoConversacion::Agente,
            'motivo_humano' => null,
            'aviso_humano_enviado' => false,
        ])->save();

        EnviadorWhatsapp::nota($conversacion, 'Agente reactivado.');
    }

    public function cerrar(WhatsappConversacion $conversacion): void
    {
        $this->marcarAtendidos($conversacion);

        $conversacion->forceFill([
            'estado' => EstadoConversacion::Cerrada,
            'sin_limite' => false,
        ])->save();

        EnviadorWhatsapp::nota($conversacion, 'Conversación cerrada.');
    }

    /**
     * Regla 33: reabre con el agente de ventas y el mismo historial.
     */
    public function reabrir(WhatsappConversacion $conversacion, string $nota = 'Conversación reabierta.'): void
    {
        $conversacion->forceFill([
            'estado' => EstadoConversacion::Agente,
            'agente_actual' => AgenteWhatsapp::Ventas,
            'motivo_humano' => null,
            'aviso_humano_enviado' => false,
            'sin_limite' => false,
        ])->save();

        EnviadorWhatsapp::nota($conversacion, $nota);
    }

    private function marcarAtendidos(WhatsappConversacion $conversacion): void
    {
        $conversacion->mensajes()->where('direccion', 'entrante')->where('respondido', false)->update(['respondido' => true]);
        $conversacion->forceFill(['pendientes_humano' => 0])->save();
    }
}
