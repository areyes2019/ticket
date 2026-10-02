<?php

namespace App\Services\Pedidos;

use App\Enums\ClaveConfiguracion;
use App\Models\Configuracion;
use App\Models\Pedido;

/**
 * Rellena con los datos del pedido los mensajes de Configuración (el que
 * acompaña al ticket y el de "ya está listo"). Blade recibe el texto ya
 * resuelto: el navegador no conoce la lista de huecos.
 */
class MensajePedido
{
    /**
     * Única lista de huecos: la usan el reemplazo y la pantalla de
     * Configuración.
     */
    public const HUECOS = [
        '{nombre}' => 'Nombre del cliente',
        '{folio}' => 'No. de ticket',
        '{total}' => 'Total del pedido',
        '{pagado}' => 'Lo que lleva pagado',
        '{saldo}' => 'Saldo pendiente',
    ];

    /**
     * null si el usuario dejó el mensaje vacío. Un hueco que no existe se
     * deja tal cual: el texto es de captura libre.
     */
    public function resolver(Pedido $pedido, ClaveConfiguracion $clave): ?string
    {
        $plantilla = Configuracion::valor($pedido->user, $clave);

        if ($plantilla === null || trim($plantilla) === '') {
            return null;
        }

        return self::rellenar($plantilla, [
            '{nombre}' => $pedido->cliente_nombre,
            '{folio}' => $pedido->numero_ticket,
            '{total}' => self::pesos($pedido->total),
            '{pagado}' => self::pesos($pedido->totalPagado()),
            '{saldo}' => self::pesos($pedido->saldoPendiente()),
        ]);
    }

    /**
     * @param  array<string, string>  $valores
     */
    public static function rellenar(string $plantilla, array $valores): string
    {
        return strtr($plantilla, $valores);
    }

    /**
     * Ejemplo para la pantalla de Configuración.
     *
     * @return array<string, string>
     */
    public static function ejemplo(): array
    {
        return [
            '{nombre}' => 'Juan Pérez',
            '{folio}' => '0042',
            '{total}' => '$850.00',
            '{pagado}' => '$600.00',
            '{saldo}' => '$250.00',
        ];
    }

    public static function pesos(float|string|null $monto): string
    {
        return '$'.number_format((float) $monto, 2);
    }
}
