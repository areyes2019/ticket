<?php

namespace App\Http\Controllers;

use App\Enums\EstadoOrdenCompra;
use App\Enums\TipoMovimiento;
use App\Exceptions\OperacionTesoreriaRechazada;
use App\Http\Requests\OrdenCompraPagoRequest;
use App\Models\OrdenCompra;
use App\Services\Tesoreria\RegistradorMovimientos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Pago de contado de una orden de compra: uno solo, por el total, que sale de
 * una cuenta de Tesorería como egreso.
 */
class OrdenCompraPagoController extends Controller
{
    public function __construct(private readonly RegistradorMovimientos $registrador) {}

    /**
     * El estado se revisa otra vez con la orden bloqueada: dos clics seguidos
     * no registran dos pagos. El egreso sale en la misma transacción (bloqueo:
     * primero la orden, después la cuenta); si dejaría la cuenta en negativo,
     * no se guarda nada y la orden sigue enviada.
     */
    public function store(OrdenCompraPagoRequest $request, OrdenCompra $ordenCompra): RedirectResponse
    {
        $cuenta = $request->user()->cuentas()->findOrFail($request->validated('cuenta_id'));

        $orden = DB::transaction(function () use ($request, $ordenCompra, $cuenta) {
            $bloqueada = OrdenCompra::whereKey($ordenCompra->id)->lockForUpdate()->firstOrFail();

            if (! $bloqueada->puedeRegistrarPago()) {
                throw ValidationException::withMessages(['cuenta_id' => OrdenCompraPagoRequest::motivoNoPagable($bloqueada)])->errorBag('pago');
            }

            $bloqueada->cuenta_id = $cuenta->id;
            $bloqueada->fecha_pago = $request->validated('fecha_pago');
            $bloqueada->estado = EstadoOrdenCompra::Pagada;
            $bloqueada->save();

            try {
                // El monto es siempre el total de la orden; uno enviado en la petición se ignora.
                $this->registrador->registrar($cuenta, TipoMovimiento::Egreso, $bloqueada->total, $request->validated('fecha_pago'), $bloqueada->conceptoPago(), $bloqueada);
            } catch (OperacionTesoreriaRechazada $rechazo) {
                throw ValidationException::withMessages(['cuenta_id' => $rechazo->getMessage()])->errorBag('pago');
            }

            return $bloqueada;
        });

        return redirect()->route('ordenes-compra.show', $ordenCompra)
            ->with('exito', 'Pago de $'.number_format((float) $orden->total, 2)." registrado en {$cuenta->nombre}. La orden quedó pagada.");
    }

    /**
     * Revierte el pago: borra el egreso (aunque la cuenta ya esté inactiva),
     * recalcula el saldo y regresa la orden a enviada. No en una recibida.
     */
    public function destroy(OrdenCompra $ordenCompra): RedirectResponse
    {
        Gate::authorize('operar', $ordenCompra);

        return DB::transaction(function () use ($ordenCompra) {
            $bloqueada = OrdenCompra::whereKey($ordenCompra->id)->lockForUpdate()->firstOrFail();

            if (! $bloqueada->puedeCancelarPago()) {
                return back()->with('error', $bloqueada->estado === EstadoOrdenCompra::Recibida
                    ? 'Una orden recibida no admite cancelar su pago.'
                    : 'La orden no tiene un pago registrado.');
            }

            $this->registrador->eliminarDeDocumento($bloqueada);

            $bloqueada->cuenta_id = null;
            $bloqueada->fecha_pago = null;
            $bloqueada->estado = EstadoOrdenCompra::Enviada;
            $bloqueada->save();

            return redirect()->route('ordenes-compra.show', $ordenCompra)->with('exito', 'Pago cancelado. La orden volvió a Enviada.');
        });
    }
}
