<?php

namespace App\Services\Whatsapp\Herramientas;

use App\Services\Whatsapp\ContextoTurno;

/**
 * Los dos agentes (041, regla 29). El cambio de estado y el aviso al cliente
 * los hace el orquestador al terminar el turno.
 */
class PasarAHumano extends HerramientaBase
{
    public function nombre(): string
    {
        return 'pasar_a_humano';
    }

    public function descripcion(): string
    {
        return 'Pasa la conversación a una persona del negocio y deja de contestar. Úsala si el cliente está molesto, pide algo que no puedes resolver '
            .'o que está fuera de tus reglas, manda un comprobante de pago, acepta una cotización, o pide cancelar o corregir una factura. '
            .'El sistema le avisa al cliente; tú no tienes que despedirte.';
    }

    public function esquema(): array
    {
        return self::objeto([
            'motivo' => ['type' => 'string', 'description' => 'Breve, para el asesor. Ej. "Cliente aceptó la cotización COT-0042".'],
        ]);
    }

    public function ejecutar(ContextoTurno $turno, array $entrada): ResultadoHerramienta
    {
        $datos = $this->validar($entrada, ['motivo' => ['required', 'string', 'max:200']]);

        if ($datos instanceof ResultadoHerramienta) {
            return $datos;
        }

        $turno->pasoAHumano = true;
        $turno->conversacion->motivo_humano = $datos['motivo'];

        return ResultadoHerramienta::ok(['ok' => true, 'nota' => 'Listo. No escribas más mensajes.'], 'pasó a humano: '.$datos['motivo']);
    }
}
