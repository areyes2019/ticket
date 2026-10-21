<?php

namespace App\Services\Ventas;

use App\Enums\EstadoCotizacion;
use App\Enums\EstadoPedido;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Única definición de "venta del día" (039, adición 3). Una venta es una
 * venta (Pedido) o una cotización con pagos sin venta; cuenta el día de su
 * primer pago (fecha_pago), entre los suyos y los de su cotización, y una
 * reembolsada no cuenta. Cualquier reporte de ventas usa este servicio para
 * que sus números coincidan con el contador de la barra.
 */
class ContadorVentas
{
    public function hoy(User $user): int
    {
        return $this->delDia($user, now(config('app.zona_negocio')));
    }

    public function delDia(User $user, CarbonInterface $dia): int
    {
        // Rango y no igualdad: así compara igual una columna date (MySQL)
        // que el texto con hora que guarda SQLite, y usa el índice (adición 2).
        $desde = $dia->toDateString();
        $hasta = $dia->copy()->addDay()->toDateString();

        $pagoDelDia = fn (Builder $pagos) => $pagos->where('fecha_pago', '>=', $desde)->where('fecha_pago', '<', $hasta);
        $pagoAnterior = fn (Builder $pagos) => $pagos->where('fecha_pago', '<', $desde);

        $ventas = $user->pedidos()
            ->where('estado', '!=', EstadoPedido::Reembolsado)
            ->where(fn (Builder $consulta) => $consulta
                ->whereHas('pagos', $pagoDelDia)
                ->orWhereHas('cotizacion.pagos', $pagoDelDia))
            ->whereDoesntHave('pagos', $pagoAnterior)
            ->whereDoesntHave('cotizacion.pagos', $pagoAnterior)
            ->count();

        // Una cotización con venta ya contó como su venta (regla 2).
        $cotizaciones = $user->cotizaciones()
            ->where('estado', '!=', EstadoCotizacion::Reembolsada)
            ->whereDoesntHave('venta')
            ->whereHas('pagos', $pagoDelDia)
            ->whereDoesntHave('pagos', $pagoAnterior)
            ->count();

        return $ventas + $cotizaciones;
    }
}
