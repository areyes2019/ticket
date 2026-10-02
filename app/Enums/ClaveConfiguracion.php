<?php

namespace App\Enums;

/**
 * Claves del almacén de configuración por usuario (tabla configuraciones).
 * Cada clave declara sus reglas, su texto por defecto y cómo se presenta.
 */
enum ClaveConfiguracion: string
{
    case MensajeTicket = 'mensaje_ticket';
    case MensajeListo = 'mensaje_listo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::MensajeTicket => 'Mensaje del ticket',
            self::MensajeListo => 'Mensaje de pedido listo',
        };
    }

    public function ayuda(): string
    {
        return match ($this) {
            self::MensajeTicket => 'Viaja junto con la imagen del ticket. Déjalo vacío para compartir solo la imagen.',
            self::MensajeListo => 'Lo manda "Avisar que está listo". Déjalo vacío para ocultar ese botón.',
        };
    }

    /**
     * present + nullable: Laravel convierte la cadena vacía en null antes de
     * validar, y un mensaje vacío es una elección válida.
     *
     * @return list<string>
     */
    public function reglas(): array
    {
        return ['present', 'nullable', 'string', 'max:2000'];
    }

    public function valorPorDefecto(): ?string
    {
        return match ($this) {
            self::MensajeTicket => <<<'TEXTO'
                ¿Qué sigue ahora?
                😊 Te explico los siguientes pasos:

                1️⃣ Primero trabajaremos en el diseño de tu sello. ✍️ Te enviaremos una propuesta para que la revises y nos confirmes si estás de acuerdo. 👀

                2️⃣ Una vez aprobado, comenzamos la producción. El tiempo estimado de entrega es de 24 a 48 horas hábiles. ⏳

                Por ejemplo, si apruebas el diseño un viernes, tu sello estará listo para el lunes. 📅

                Cuando esté terminado, podrás elegir cómo recibirlo:
                📍 Recogerlo personalmente o
                🚚 Recibirlo por paquetería, directo a tu domicilio.

                ¿Te parece bien? Quedo atento(a) a tu confirmación. 😉
                TEXTO,
            self::MensajeListo => <<<'TEXTO'
                Hola {nombre} 👋
                Tu pedido con No. de ticket {folio} ya está listo. 🎉
                Puedes pasar por él cuando gustes. ¡Gracias por tu preferencia!
                TEXTO,
        };
    }
}
