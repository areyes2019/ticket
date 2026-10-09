<?php

namespace App\Http\Controllers;

use App\Enums\ClaveConfiguracion;
use App\Models\Cuenta;
use App\Models\Pedido;
use App\Services\Pedidos\MensajePedido;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Las pantallas que cierran la venta del mostrador (033): el cobro y el
 * ticket. La cotización y la factura terminan en su detalle (034,
 * MostradorConsultaController).
 */
class MostradorResultadoController extends Controller
{
    /**
     * Cobro de la venta recién creada. La caja (la cuenta de efectivo activa
     * más antigua) va preseleccionada: aquí se cobra la venta que ocurre
     * enfrente. Sin saldo no hay nada que cobrar y se pasa al ticket.
     */
    public function cobro(Request $request, Pedido $pedido): View|RedirectResponse
    {
        Gate::authorize('operar', $pedido);

        if (! $pedido->puedeRegistrarPago()) {
            return redirect()->route('mostrador.venta.listo', $pedido);
        }

        $cuentas = $request->user()->cuentas()->activas()->orderBy('nombre')->get(['id', 'nombre', 'tipo']);
        $caja = Cuenta::cajaEntre($cuentas);

        return view('mostrador.cobro', [
            'pedido' => $pedido,
            'cuentas' => $cuentas,
            'cuentaElegida' => old('cuenta_id', $caja?->id),
            'hoy' => now(config('app.zona_negocio'))->toDateString(),
        ]);
    }

    public function venta(Pedido $pedido, MensajePedido $mensajes): View
    {
        Gate::authorize('operar', $pedido);

        return view('mostrador.venta-listo', [
            'pedido' => $pedido,
            'mensajeTicket' => $mensajes->resolver($pedido, ClaveConfiguracion::MensajeTicket),
        ]);
    }
}
