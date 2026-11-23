<?php

namespace App\Enums;

/**
 * Los dos agentes del número de WhatsApp (041, regla 1).
 */
enum AgenteWhatsapp: string
{
    case Ventas = 'ventas';
    case Facturacion = 'facturacion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Ventas => 'Ventas',
            self::Facturacion => 'Facturación',
        };
    }

    /**
     * Secciones de las indicaciones del agente, en el orden fijo en que se
     * muestran y se le mandan (045, regla 1). `preguntas` es la lista de pares
     * pregunta/respuesta; las demás son textos.
     *
     * @return array<string, array{titulo: string, ejemplo: string}>
     */
    public function secciones(): array
    {
        $tono = ['titulo' => 'Tono y forma de hablar', 'ejemplo' => 'Trata de "tú", con amabilidad y sin tecnicismos.'];
        $preguntas = ['titulo' => 'Preguntas frecuentes', 'ejemplo' => ''];
        $otras = ['titulo' => 'Otras indicaciones', 'ejemplo' => 'Lo que no quepa en las secciones anteriores.'];

        return match ($this) {
            self::Ventas => [
                'pago' => ['titulo' => 'Formas de pago', 'ejemplo' => 'Transferencia, tarjeta y efectivo. Pedimos 50 % de anticipo en pedidos grandes.'],
                'entregas' => ['titulo' => 'Entregas y envíos', 'ejemplo' => 'Entregamos en 3 a 5 días hábiles; envíos a todo México por paquetería.'],
                'devoluciones' => ['titulo' => 'Cambios y devoluciones', 'ejemplo' => 'Los sellos personalizados no tienen cambio; si llega dañado lo reponemos.'],
                'tono' => $tono,
                'preguntas' => $preguntas,
                'otras' => $otras,
            ],
            self::Facturacion => [
                'requisitos' => ['titulo' => 'Requisitos para facturar', 'ejemplo' => 'RFC, razón social, código postal, régimen fiscal y uso del CFDI.'],
                'plazos' => ['titulo' => 'Plazos para pedir factura', 'ejemplo' => 'Se puede facturar dentro del mismo mes de la compra.'],
                'tono' => $tono,
                'preguntas' => $preguntas,
                'otras' => $otras,
            ],
        };
    }

    /**
     * Las secciones de texto, sin `preguntas`.
     *
     * @return array<string, array{titulo: string, ejemplo: string}>
     */
    public function seccionesTexto(): array
    {
        return array_diff_key($this->secciones(), ['preguntas' => true]);
    }

    public function columnaIndicaciones(): string
    {
        return 'indicaciones_'.$this->value;
    }
}
