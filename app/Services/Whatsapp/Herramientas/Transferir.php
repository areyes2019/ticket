<?php

namespace App\Services\Whatsapp\Herramientas;

use App\Enums\AgenteWhatsapp;
use App\Services\Whatsapp\ContextoTurno;

/**
 * transferir_a_facturacion / transferir_a_ventas (041, regla 2). El otro
 * agente sigue en el mismo turno, con el mismo historial.
 */
class Transferir extends HerramientaBase
{
    public function __construct(private AgenteWhatsapp $destino) {}

    public function nombre(): string
    {
        return 'transferir_a_'.$this->destino->value;
    }

    public function descripcion(): string
    {
        return match ($this->destino) {
            AgenteWhatsapp::Facturacion => 'Pasa la conversación al agente de facturación cuando el cliente quiere facturar una compra o que le reenvíen una factura. No escribas nada más: él contesta.',
            AgenteWhatsapp::Ventas => 'Pasa la conversación al agente de ventas cuando el cliente pregunta por productos, precios o quiere cotizar. No escribas nada más: él contesta.',
        };
    }

    public function esquema(): array
    {
        return self::objeto([]);
    }

    public function ejecutar(ContextoTurno $turno, array $entrada): ResultadoHerramienta
    {
        if (! $turno->numero()->agenteActivo($this->destino)) {
            return ResultadoHerramienta::error('El agente de '.mb_strtolower($this->destino->etiqueta()).' está apagado. Usa pasar_a_humano para que un asesor ayude.');
        }

        $turno->transferirA = $this->destino;

        return ResultadoHerramienta::ok(['ok' => true], 'transfirió a '.mb_strtolower($this->destino->etiqueta()));
    }
}
