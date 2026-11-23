<?php

namespace App\Services\Whatsapp;

use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Services\Whatsapp\Claude\ClienteClaude;

/**
 * Regla 75: cuando lo no resumido pasa de 40 + 20 mensajes, los más viejos
 * se condensan en el resumen y el agente solo ve los últimos 40. Así el
 * cliente no repite datos y el costo no crece sin fin.
 */
class Resumidor
{
    public function __construct(
        private ClienteClaude $claude,
        private RegistroUso $uso,
    ) {}

    public function actualizarSiHaceFalta(WhatsappConversacion $conversacion): void
    {
        $historial = config('whatsapp.mensajes_historial');

        $sinResumir = $conversacion->mensajes()
            ->whereIn('direccion', [WhatsappMensaje::ENTRANTE, WhatsappMensaje::SALIENTE])
            ->when($conversacion->resumen_hasta_mensaje_id, fn ($q, $hasta) => $q->where('id', '>', $hasta));

        $total = (clone $sinResumir)->count();

        if ($total <= $historial + config('whatsapp.resumir_cada')) {
            return;
        }

        $viejos = (clone $sinResumir)->limit($total - $historial)->get();

        $transcripcion = $viejos->map(fn (WhatsappMensaje $m) => ($m->esDelCliente() ? 'Cliente: ' : 'Negocio: ').OrquestadorTurno::comoTexto($m))->implode("\n");

        $peticion = ($conversacion->resumen ? "Resumen previo:\n".$conversacion->resumen."\n\n" : '')
            ."Mensajes nuevos:\n".$transcripcion;

        $respuesta = $this->claude->crear(
            [['type' => 'text', 'text' => 'Resumes conversaciones de WhatsApp entre un negocio de sellos y su cliente, para que un asistente las retome. '
                .'Escribe en español, en un párrafo de no más de 150 palabras: qué quiere el cliente, datos que dio (nombre, texto del sello, medidas, datos fiscales), '
                .'cotizaciones o facturas mencionadas con su folio, y qué quedó pendiente. Solo hechos de la conversación; nada inventado.']],
            [['role' => 'user', 'content' => $peticion]],
        );

        $this->uso->registrar($conversacion->user, $conversacion, $respuesta);

        if ($respuesta->texto() === '') {
            return;
        }

        $conversacion->forceFill([
            'resumen' => $respuesta->texto(),
            'resumen_hasta_mensaje_id' => $viejos->last()->id,
        ])->save();
    }
}
