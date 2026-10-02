<?php

namespace App\Http\Controllers;

use App\Enums\TipoMovimiento;
use App\Exceptions\OperacionTesoreriaRechazada;
use App\Http\Requests\EntregarPedidoRequest;
use App\Models\Cuenta;
use App\Models\Pedido;
use App\Models\PedidoPago;
use App\Services\Tesoreria\RegistradorMovimientos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Destino del QR del ticket y de la etiqueta. La pantalla se resuelve en el
 * servidor según el estado: ya entregado (solo informa), saldo en cero (se
 * cierra solo, con "Deshacer") o saldo pendiente (pide la cuenta y confirma).
 */
class PedidoEntregaController extends Controller
{
    public function __construct(private readonly RegistradorMovimientos $registrador) {}

    public function show(Pedido $pedido): View
    {
        Gate::authorize('operar', $pedido);

        return view('pedidos.entregar', [
            'pedido' => $pedido,
            'cuentas' => $pedido->user->cuentas()->activas()->orderBy('nombre')->pluck('nombre', 'id')->all(),
            'puedeDeshacer' => session('entrega_sin_cobro') === $pedido->id && $pedido->puedeDeshacerEntrega(),
        ]);
    }

    /**
     * Idempotente: con el pedido bloqueado, uno ya entregado no se toca. Con
     * saldo, registra un pago por el saldo exacto (el monto no viaja en la
     * petición) en la cuenta elegida.
     */
    public function store(EntregarPedidoRequest $request, Pedido $pedido): RedirectResponse
    {
        $resultado = DB::transaction(function () use ($request, $pedido) {
            $bloqueado = Pedido::whereKey($pedido->id)->lockForUpdate()->firstOrFail();

            if ($bloqueado->estaEntregado()) {
                return ['ya' => $bloqueado];
            }

            $cobro = null;

            if ($bloqueado->tieneSaldo()) {
                if (! $request->filled('cuenta_id')) {
                    throw ValidationException::withMessages(['cuenta_id' => 'Elige la cuenta a la que entra el cobro.']);
                }

                $cobro = $this->cobrarSaldo($bloqueado, $request->user()->cuentas()->findOrFail($request->integer('cuenta_id')));
                // Queda pagado antes de entregarse: nace el enlace de autofactura.
                $bloqueado->recalcularEstado();
            }

            $bloqueado->marcarEntregado();
            $bloqueado->save();

            return ['cobro' => $cobro];
        });

        $destino = redirect()->route('pedidos.entregar', $pedido);

        if (isset($resultado['ya'])) {
            return $destino->with('error', 'Este pedido ya se entregó el '.$resultado['ya']->entregado_en->setTimezone(config('app.zona_negocio'))->format('d/m/Y \a \l\a\s H:i').'.');
        }

        if ($resultado['cobro'] === null) {
            return $destino->with('exito', "Pedido {$pedido->folio_formateado} entregado.")->with('entrega_sin_cobro', $pedido->id);
        }

        $cobro = $resultado['cobro'];

        return $destino->with('exito', 'Cobro de $'.number_format((float) $cobro->monto, 2)." registrado en {$cobro->cuenta->nombre}. Pedido entregado.");
    }

    /**
     * Solo las entregas que no cobraron, dentro de la ventana del servidor:
     * así nunca hay un movimiento de Tesorería que revertir.
     */
    public function destroy(Pedido $pedido): RedirectResponse
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

        if ($motivo !== null) {
            return redirect()->route('pedidos.show', $pedido)->with('error', $motivo);
        }

        return redirect()->route('pedidos.show', $pedido)
            ->with('exito', "Se deshizo la entrega del pedido {$pedido->folio_formateado}.");
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
            throw ValidationException::withMessages(['cuenta_id' => $rechazo->getMessage()]);
        }

        return $pago;
    }
}
