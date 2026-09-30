<?php

namespace App\Support\Demo;

use Illuminate\Support\Carbon;

/**
 * Datos ficticios de la bandeja de correo del dashboard (spec 013).
 *
 * Es la única fuente de los correos de demostración: nada se guarda ni se envía.
 * Las fechas se calculan desde el momento actual para que la bandeja se vea reciente.
 */
class BandejaCorreoDemo
{
    /**
     * Carpetas con su contador de no leídos. Destacados e Importantes no son
     * carpetas propias: reúnen los correos marcados de cualquier carpeta.
     *
     * @return list<array{clave: string, nombre: string, icono: string, no_leidos: int}>
     */
    public function carpetas(): array
    {
        $correos = $this->correos();

        return array_map(fn (array $carpeta) => $carpeta + [
            'no_leidos' => count(array_filter(
                $correos,
                fn (array $correo) => ! $correo['leido'] && self::correoEnCarpeta($correo, $carpeta['clave']),
            )),
        ], [
            ['clave' => 'entrada', 'nombre' => 'Bandeja de entrada', 'icono' => 'inbox'],
            ['clave' => 'enviados', 'nombre' => 'Enviados', 'icono' => 'send'],
            ['clave' => 'borradores', 'nombre' => 'Borradores', 'icono' => 'file-earmark'],
            ['clave' => 'spam', 'nombre' => 'Spam', 'icono' => 'exclamation-octagon'],
            ['clave' => 'papelera', 'nombre' => 'Papelera', 'icono' => 'trash'],
            ['clave' => 'destacados', 'nombre' => 'Destacados', 'icono' => 'star'],
            ['clave' => 'importantes', 'nombre' => 'Importantes', 'icono' => 'bookmark'],
        ]);
    }

    /**
     * @return list<array{clave: string, nombre: string}>
     */
    public function etiquetas(): array
    {
        return [
            ['clave' => 'clientes', 'nombre' => 'Clientes'],
            ['clave' => 'proveedores', 'nombre' => 'Proveedores'],
            ['clave' => 'facturas', 'nombre' => 'Facturas'],
            ['clave' => 'cotizaciones', 'nombre' => 'Cotizaciones'],
        ];
    }

    /**
     * Correos del más reciente al más antiguo.
     *
     * @return list<array<string, mixed>>
     */
    public function correos(): array
    {
        $ahora = Carbon::now(config('app.zona_negocio'));

        $correos = [
            [
                'id' => 1,
                'carpeta' => 'entrada',
                'etiqueta' => 'cotizaciones',
                'remitente' => ['nombre' => 'Laura Méndez', 'correo' => 'laura.mendez@ejemplo.mx'],
                'asunto' => 'Aceptamos la cotización COT-0042',
                'cuerpo' => [
                    'Buenos días:',
                    'Revisamos la cotización COT-0042 con el equipo de compras y estamos de acuerdo con los precios y el tiempo de entrega.',
                    'Les pedimos que procedan con el pedido. El anticipo del 50 % se transfiere hoy por la tarde; en cuanto lo tengan, por favor confírmenme.',
                    'Saludos cordiales,',
                    'Laura Méndez · Papelería La Esquina',
                ],
                'fecha' => $ahora->copy()->subMinutes(15),
                'leido' => false,
                'destacado' => true,
                'importante' => true,
                'adjunto' => ['nombre' => 'COT-0042.pdf', 'tamano' => '184 KB'],
            ],
            [
                'id' => 2,
                'carpeta' => 'entrada',
                'etiqueta' => 'facturas',
                'remitente' => ['nombre' => 'Jorge Castañeda', 'correo' => 'contabilidad@ejemplo.com'],
                'asunto' => '¿Me pueden enviar la factura de la compra del lunes?',
                'cuerpo' => [
                    'Hola, buen día.',
                    'El lunes pasamos a recoger material de oficina y nos comentaron que la factura llegaría por correo, pero todavía no la recibimos.',
                    'Nuestros datos fiscales no han cambiado. El uso de CFDI es "Gastos en general".',
                    'Gracias de antemano.',
                ],
                'fecha' => $ahora->copy()->subHours(2)->subMinutes(10),
                'leido' => false,
                'destacado' => false,
                'importante' => true,
                'adjunto' => null,
            ],
            [
                'id' => 3,
                'carpeta' => 'entrada',
                'etiqueta' => 'proveedores',
                'remitente' => ['nombre' => 'Distribuidora del Bajío', 'correo' => 'ventas@ejemplo.com'],
                'asunto' => 'Nueva lista de precios vigente a partir de octubre',
                'cuerpo' => [
                    'Estimado cliente:',
                    'Le compartimos la lista de precios que entra en vigor el 1 de octubre. Los artículos de las líneas de archivo y escritura tienen un ajuste promedio del 4 %.',
                    'Los pedidos colocados antes del 30 de septiembre se respetan con la lista anterior.',
                    'Quedamos a sus órdenes.',
                ],
                'fecha' => $ahora->copy()->subHours(5),
                'leido' => false,
                'destacado' => true,
                'importante' => false,
                'adjunto' => ['nombre' => 'lista-precios-octubre.pdf', 'tamano' => '1.2 MB'],
            ],
            [
                'id' => 4,
                'carpeta' => 'entrada',
                'etiqueta' => 'facturas',
                'remitente' => ['nombre' => 'Banco Ejemplo', 'correo' => 'notificaciones@ejemplo.com'],
                'asunto' => 'Recibiste una transferencia por $12,480.00',
                'cuerpo' => [
                    'Te informamos que recibiste una transferencia SPEI en tu cuenta terminación 4821.',
                    'Monto: $12,480.00 MXN. Concepto: "Pago factura A-1187". Ordenante: Comercializadora Norte, S.A. de C.V.',
                    'Este es un mensaje automático, por favor no respondas a este correo.',
                ],
                'fecha' => $ahora->copy()->subDay()->setTime(17, 32),
                'leido' => true,
                'destacado' => false,
                'importante' => false,
                'adjunto' => null,
            ],
            [
                'id' => 5,
                'carpeta' => 'entrada',
                'etiqueta' => 'clientes',
                'remitente' => ['nombre' => 'Ricardo Sotelo', 'correo' => 'r.sotelo@ejemplo.mx'],
                'asunto' => 'Cambio de domicilio fiscal',
                'cuerpo' => [
                    'Hola, ¿qué tal?',
                    'Les aviso que cambiamos de domicilio fiscal. Adjunto la constancia de situación fiscal actualizada para que la registren en su sistema antes de la próxima factura.',
                    'Saludos.',
                ],
                'fecha' => $ahora->copy()->subDay()->setTime(10, 5),
                'leido' => true,
                'destacado' => false,
                'importante' => false,
                'adjunto' => ['nombre' => 'constancia-situacion-fiscal.pdf', 'tamano' => '96 KB'],
            ],
            [
                'id' => 6,
                'carpeta' => 'entrada',
                'etiqueta' => 'cotizaciones',
                'remitente' => ['nombre' => 'Ana Lucía Torres', 'correo' => 'compras@ejemplo.com'],
                'asunto' => 'Solicitud de cotización: 200 carpetas y 50 cajas de archivo',
                'cuerpo' => [
                    'Buenas tardes:',
                    'Necesitamos una cotización para 200 carpetas tamaño carta color azul y 50 cajas de archivo muerto, con entrega en nuestras oficinas de Querétaro.',
                    '¿Podrían incluir el tiempo de entrega y si manejan descuento por volumen?',
                    'Gracias.',
                ],
                'fecha' => $ahora->copy()->subDays(3)->setTime(13, 47),
                'leido' => true,
                'destacado' => false,
                'importante' => false,
                'adjunto' => null,
            ],
            [
                'id' => 7,
                'carpeta' => 'entrada',
                'etiqueta' => 'proveedores',
                'remitente' => ['nombre' => 'Papeles Finos de México', 'correo' => 'pedidos@ejemplo.mx'],
                'asunto' => 'Tu pedido PF-2291 salió a ruta',
                'cuerpo' => [
                    'Tu pedido PF-2291 ya salió de nuestro almacén y llegará mañana entre las 9:00 y las 14:00.',
                    'Recuerda revisar la mercancía al recibirla; cualquier faltante repórtalo el mismo día.',
                ],
                'fecha' => $ahora->copy()->subDays(5)->setTime(9, 20),
                'leido' => true,
                'destacado' => false,
                'importante' => false,
                'adjunto' => null,
            ],
            [
                'id' => 8,
                'carpeta' => 'enviados',
                'etiqueta' => 'facturas',
                'remitente' => ['nombre' => 'Yo', 'correo' => 'facturacion@ejemplo.com'],
                'destinatario' => 'Comercializadora Norte <pagos@ejemplo.com>',
                'asunto' => 'Factura A-1187 (PDF y XML)',
                'cuerpo' => [
                    'Hola, buen día.',
                    'Les envío la factura A-1187 correspondiente a su pedido del 22 de septiembre, en PDF y XML.',
                    'Quedo atento a cualquier duda.',
                ],
                'fecha' => $ahora->copy()->subDays(2)->setTime(11, 15),
                'leido' => true,
                'destacado' => false,
                'importante' => false,
                'adjunto' => ['nombre' => 'A-1187.pdf', 'tamano' => '212 KB'],
            ],
            [
                'id' => 9,
                'carpeta' => 'enviados',
                'etiqueta' => 'cotizaciones',
                'remitente' => ['nombre' => 'Yo', 'correo' => 'ventas@ejemplo.com'],
                'destinatario' => 'Laura Méndez <laura.mendez@ejemplo.mx>',
                'asunto' => 'Cotización COT-0042',
                'cuerpo' => [
                    'Hola, Laura:',
                    'Te comparto la cotización que platicamos por teléfono. Los precios ya incluyen IVA y tienen vigencia de 15 días.',
                    'Saludos.',
                ],
                'fecha' => $ahora->copy()->subDays(4)->setTime(16, 40),
                'leido' => true,
                'destacado' => false,
                'importante' => false,
                'adjunto' => ['nombre' => 'COT-0042.pdf', 'tamano' => '184 KB'],
            ],
            [
                'id' => 10,
                'carpeta' => 'borradores',
                'etiqueta' => 'clientes',
                'remitente' => ['nombre' => 'Yo', 'correo' => 'ventas@ejemplo.com'],
                'destinatario' => 'Ana Lucía Torres <compras@ejemplo.com>',
                'asunto' => 'Seguimiento a su solicitud de carpetas',
                'cuerpo' => [
                    'Hola, Ana Lucía:',
                    'Gracias por escribirnos. Estamos confirmando existencias con el proveedor y mañana le enviamos la cotización con el descuento por volumen.',
                ],
                'fecha' => $ahora->copy()->subDays(2)->setTime(18, 2),
                'leido' => true,
                'destacado' => false,
                'importante' => false,
                'adjunto' => null,
            ],
            [
                'id' => 11,
                'carpeta' => 'spam',
                'etiqueta' => null,
                'remitente' => ['nombre' => 'Premios Express', 'correo' => 'ganaste@ejemplo.com'],
                'asunto' => '¡¡Felicidades!! Ganaste un celular nuevo',
                'cuerpo' => [
                    '¡Fuiste seleccionado para recibir un celular de última generación totalmente gratis!',
                    'Solo haz clic en el enlace y captura los datos de tu tarjeta para cubrir el envío.',
                ],
                'fecha' => $ahora->copy()->subDays(6)->setTime(3, 12),
                'leido' => false,
                'destacado' => false,
                'importante' => false,
                'adjunto' => null,
            ],
        ];

        $correos = array_map(fn (array $correo) => $correo + [
            'destinatario' => 'Yo <ventas@ejemplo.com>',
        ], $correos);

        usort($correos, fn (array $a, array $b) => $b['fecha'] <=> $a['fecha']);

        return $correos;
    }

    /**
     * @param  array<string, mixed>  $correo
     */
    public static function correoEnCarpeta(array $correo, string $carpeta): bool
    {
        return match ($carpeta) {
            'destacados' => $correo['destacado'],
            'importantes' => $correo['importante'],
            default => $correo['carpeta'] === $carpeta,
        };
    }
}
