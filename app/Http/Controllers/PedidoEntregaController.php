<?php

namespace App\Http\Controllers;

use App\Enums\TipoMovimiento;
use App\Exceptions\OperacionTesoreriaRechazada;
use App\Http\Requests\EntregarPedidoRequest;
use App\Models\Cuenta;
use App\Models\OrdenTrabajo;
use App\Models\Pedido;
use App\Models\PedidoPago;
use App\Services\Tesoreria\RegistradorMovimientos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * El botón "Entregado" de la venta, de su orden de trabajo y del visor del
 * dashboard (022, corrección 1). Regresa a donde se pulsó (origen).
 */
class PedidoEntregaController extends Controller
{
    public function __construct(private readonly RegistradorMovimientos $registrador) {}

    /**
     * Idempotente: con el pedido bloqueado, uno ya entregado no se toca. Una
     * venta con orden de trabajo sin terminar tampoco. Con saldo, registra un
     * pago por el saldo exacto (el monto no viaja en la petición) en la
     * cuenta elegida.
     */
    public function store(EntregarPedidoRequest $request, Pedido $pedido): RedirectResponse
    {
        $resultado = DB::transaction(function () use ($request, $pedido) {
            $bloqueado = Pedido::whereKey($pedido->id)->lockForUpdate()->firstOrFail();

            if ($bloqueado->estaEntregado()) {
                return ['ya' => $bloqueado];
            }

            // Bloqueo venta → orden, el mismo orden que "avanzar".
            $orden = OrdenTrabajo::where('pedido_id', $bloqueado->id)->lockForUpdate()->first();
            $bloqueado->setRelation('ordenTrabajo', $orden);
            $motivo = $bloqueado->motivoNoEntrega();

            if ($motivo !== null) {
                return ['motivo' => $motivo];
            }

            $cobro = null;

            if ($bloqueado->tieneSaldo()) {
                if (! $request->filled('cuenta_id')) {
                    throw ValidationException::withMessages(['cuenta_id' => 'Elige la cuenta a la que entra el cobro.'])->errorBag('entrega');
                }

                $cobro = $this->cobrarSaldo($bloqueado, $request->user()->cuentas()->findOrFail($request->integer('cuenta_id')));
                // Queda pagado antes de entregarse: nace el enlace de autofactura.
                $bloqueado->recalcularEstado();
            }

            $bloqueado->marcarEntregado();
            $bloqueado->save();

            return ['cobro' => $cobro];
        });

        // Desde el dashboard regresa sin ?ot=: la orden entregada ya no está en la lista.
        $destino = redirect($this->destino($request, $pedido, conOrden: false));

        if (isset($resultado['motivo'])) {
            return $destino->with('error', $resultado['motivo']);
        }

        if (isset($resultado['ya'])) {
            return $destino->with('error', 'Esta venta ya se entregó el '.$resultado['ya']->entregado_en->setTimezone(config('app.zona_negocio'))->format('d/m/Y \a \l\a\s H:i').'.');
        }

        if ($resultado['cobro'] === null) {
            return $destino->with('exito', "{$pedido->folio_formateado} entregado.");
        }

        $cobro = $resultado['cobro'];

        return $destino->with('exito', 'Cobro de $'.number_format((float) $cobro->monto, 2)." registrado en {$cobro->cuenta->nombre}. {$pedido->folio_formateado} entregado.");
    }

    /**
     * Solo las entregas que no cobraron, dentro de la ventana del servidor:
     * así nunca hay un movimiento de Tesorería que revertir.
     */
    public function destroy(Request $request, Pedido $pedido): RedirectResponse
    {
        Gate::authorize('operar', $pedido);

        $motivo = DB::transaction(function () use ($pedido) {
            $bloqueado = Pedido::whereKey($pedido->id)->lockForUpdate()->firstOrFail();

            if (! $bloqueado->estaEntregado()) {
                return 'El pedido no está entregado.';
            }

            if ($bloqueado->pagos()->where('registrado_al_entregar', true)->exists()) {
                return 'Esta entrega registró un cobro: corrígelo desde el detalle del pedido.';
            }

            if (! $bloqueado->puedeDeshacerEntrega()) {
                return 'Ya pasaron más de '.Pedido::MINUTOS_DESHACER_ENTREGA.' minutos: la entrega no se puede deshacer.';
            }

            $bloqueado->deshacerEntrega();
            $bloqueado->save();

            return null;
        });

        // La orden regresó a terminado: desde el dashboard vuelve abierta.
        $destino = redirect($this->destino($request, $pedido, conOrden: true));

        if ($motivo !== null) {
            return $destino->with('error', $motivo);
        }

        return $destino->with('exito', "Se deshizo la entrega de {$pedido->folio_formateado}.");
    }

    /**
     * Regresa a donde se pulsó el botón: la venta (por omisión), su orden de
     * trabajo o el dashboard.
     */
    private function destino(Request $request, Pedido $pedido, bool $conOrden): string
    {
        $orden = $pedido->ordenTrabajo()->first();

        return match (true) {
            $request->input('origen') === 'dashboard' => $conOrden && $orden !== null
                ? route('dashboard', ['ot' => $orden->id])
                : route('dashboard'),
            $request->input('origen') === 'orden' && $orden !== null => route('pedidos.orden-trabajo.show', $pedido),
            default => route('pedidos.show', $pedido),
        };
    }

    /**
     * Pago por el saldo exacto, marcado como registrado al entregar, con su
     * ingreso (bloqueo: pedido, después cuenta).
     */
    private function cobrarSaldo(Pedido $pedido, Cuenta $cuenta): PedidoPago
    {
        $pago = new PedidoPago([
            'fecha_pago' => now(config('app.zona_negocio'))->toDateString(),
            'cuenta_id' => $cuenta->id,
            'monto' => $pedido->saldoPendiente(),
        ]);
        $pago->registrado_al_entregar = true;
        $pedido->pagos()->save($pago);
        $pago->setRelation('pedido', $pedido);
        $pago->setRelation('cuenta', $cuenta);

        try {
            $this->registrador->registrar($cuenta, TipoMovimiento::Ingreso, $pago->monto, $pago->fecha_pago->toDateString(), $pago->conceptoMovimiento(), $pago);
        } catch (OperacionTesoreriaRechazada $rechazo) {
            throw ValidationException::withMessages(['cuenta_id' => $rechazo->getMessage()])->errorBag('entrega');
        }

        return $pago;
    }
}
