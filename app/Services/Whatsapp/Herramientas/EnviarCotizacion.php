<?php

namespace App\Services\Whatsapp\Herramientas;

use App\Services\Cotizaciones\GeneradorPdfCotizacion;
use App\Services\Whatsapp\ContextoTurno;
use App\Services\Whatsapp\EnviadorWhatsapp;

/**
 * Ventas (041, regla 39): manda el PDF y la deja enviada, como al
 * compartirla desde el sistema. Solo cotizaciones de esta conversación.
 */
class EnviarCotizacion extends HerramientaBase
{
    public function __construct(
        private GeneradorPdfCotizacion $pdf,
        private EnviadorWhatsapp $enviador,
    ) {}

    public function nombre(): string
    {
        return 'enviar_cotizacion';
    }

    public function descripcion(): string
    {
        return 'Manda al cliente, por este chat, el PDF de una cotización creada en esta conversación con crear_cotizacion.';
    }

    public function esquema(): array
    {
        return self::objeto([
            'cotizacion_id' => ['type' => 'integer'],
        ]);
    }

    public function ejecutar(ContextoTurno $turno, array $entrada): ResultadoHerramienta
    {
        $datos = $this->validar($entrada, ['cotizacion_id' => ['required', 'integer']]);

        if ($datos instanceof ResultadoHerramienta) {
            return $datos;
        }

        $cotizacion = $turno->conversacion->cotizaciones()->whereKey($datos['cotizacion_id'])->first();

        if ($cotizacion === null) {
            return ResultadoHerramienta::error('Esa cotización no es de esta conversación.');
        }

        $this->enviador->documento(
            $turno->conversacion,
            $this->pdf->contenido($cotizacion),
            $this->pdf->nombreArchivo($cotizacion),
            'application/pdf',
            $turno->conversacion->agente_actual->value,
            pie: 'Cotización '.$cotizacion->folio_formateado,
            datos: ['cotizacion_id' => $cotizacion->id],
        );
        $turno->documentosEnviados++;

        $cotizacion->marcarEnviada();

        return ResultadoHerramienta::ok(['enviada' => true, 'folio' => $cotizacion->folio_formateado], 'envió la cotización '.$cotizacion->folio_formateado);
    }
}
