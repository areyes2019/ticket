<?php

namespace App\Http\Controllers;

use App\Enums\TipoMovimiento;
use App\Exceptions\OperacionTesoreriaRechazada;
use App\Http\Requests\PedidoPagoRequest;
use App\Models\Pedido;
use App\Models\PedidoPago;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use App\Services\Tesoreria\RegistradorMovimientos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PedidoPagoController extends Controller
{
    public function __construct(private readonly RegistradorMovimientos $registrador) {}

    /**
     * Las reglas se revisan otra vez con el pedido bloqueado: dos clics
     * seguidos no generan un sobrepago. El pago entra a su cuenta como
     * ingreso en la misma transacción (bloqueo: primero el pedido, después la
     * cuenta). Al quedar pagado nace el enlace de autofactura.
     */
    public function store(PedidoPagoRequest $request, Pedido $pedido): RedirectResponse
    {
        $cuenta = $request->user()->cuentas()->findOrFail($request->validated('cuenta_id'));

        $pago = DB::transaction(function () use ($request, $pedido, $cuenta) {
            $bloqueado = Pedido::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            $monto = CalculadoraTotalesDocumento::pesos(CalculadoraTotalesDocumento::centavos($request->validated('monto')));
            $motivo = PedidoPagoRequest::motivoRechazo($bloqueado, $monto);

            if ($motivo !== null) {
                throw ValidationException::withMessages(['monto' => $motivo])->errorBag('pago');
            }

            $pago = $bloqueado->pagos()->create([
                'fecha_pago' => $request->validated('fecha_pago'),
                'cuenta_id' => $cuenta->id,
                'monto' => $monto,
            ]);
            $pago->setRelation('pedido', $bloqueado);

            try {
                $this->registrador->registrar($cuenta, TipoMovimiento::Ingreso, $pago->monto, $pago->fecha_pago->toDateString(), $pago->conceptoMovimiento(), $pago);
            } catch (OperacionTesoreriaRechazada $rechazo) {
                throw ValidationException::withMessages(['cuenta_id' => $rechazo->getMessage()])->errorBag('pago');
            }

            $bloqueado->recalcularEstado();
            $bloqueado->save();

            return $pago;
        });

        return redirect()->route('pedidos.show', $pedido)
            ->with('exito', 'Pago de $'.number_format((float) $pago->monto, 2)." registrado en {$cuenta->nombre}.");
    }

    /**
     * Cualquier pago (sin regla LIFO: cada uno lleva su propio monto), salvo
     * que el pedido ya tenga factura timbrada. Se lleva su ingreso de
     * Tesorería, salvo que la cuenta quede en negativo. Es también la forma de
     * corregir un cobro mal capturado al entregar.
     */
    public function destroy(Pedido $pedido, PedidoPago $pago): RedirectResponse
    {
        Gate::authorize('operar', $pedido);

        return DB::transaction(function () use ($pedido, $pago) {
            $bloqueado = Pedido::whereKey($pedido->id)->lockForUpdate()->firstOrFail();

            if (! $bloqueado->puedeEliminarPago()) {
                return back()->with('error', 'El pedido ya tiene factura timbrada: sus pagos no se eliminan.');
            }

            try {
                $this->registrador->eliminarDeDocumento($pago);
            } catch (OperacionTesoreriaRechazada $rechazo) {
                return back()->with('error', "No se puede eliminar el pago: la cuenta {$rechazo->cuenta->nombre} quedaría con saldo negativo.");
            }

            $pago->delete();

            $bloqueado->recalcularEstado();
            $bloqueado->save();

            return redirect()->route('pedidos.show', $pedido)->with('exito', 'Pago eliminado.');
        });
    }
}
