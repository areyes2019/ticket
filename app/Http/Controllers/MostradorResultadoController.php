<?php

namespace App\Http\Controllers;

use App\Enums\ClaveConfiguracion;
use App\Enums\EstadoFactura;
use App\Enums\TipoCuenta;
use App\Enums\TipoErrorTimbrado;
use App\Models\Cotizacion;
use App\Models\Factura;
use App\Models\Pedido;
use App\Services\Pedidos\MensajePedido;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Las pantallas que cierran cada captura del mostrador (033). Las rutas de
 * alta de siempre regresan aquí cuando reciben origen=mostrador.
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
        $caja = $cuentas->where('tipo', TipoCuenta::Efectivo)->sortBy('id')->first();

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

    public function cotizacion(Cotizacion $cotizacion): View
    {
        Gate::authorize('operar', $cotizacion);

        $cotizacion->load('cliente')->loadCount('lineas');

        return view('mostrador.cotizacion-listo', ['cotizacion' => $cotizacion]);
    }

    /**
     * El resultado del timbrado, leído de la factura: timbrada, pendiente por
     * el PAC (se reintenta aquí) o rechazada por datos (se corrige en la
     * computadora: los mismos datos volverían a fallar).
     */
    public function factura(Factura $factura): View
    {
        Gate::authorize('operar', $factura);

        $factura->load('cliente')->loadCount('lineas');

        return view('mostrador.factura-listo', [
            'factura' => $factura,
            'timbrada' => $factura->estado === EstadoFactura::Timbrada,
            'reintentable' => $factura->puedeReintentarse() && $factura->tipo_error_timbrado !== TipoErrorTimbrado::Datos,
        ]);
    }
}
