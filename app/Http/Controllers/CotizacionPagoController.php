<?php

namespace App\Http\Controllers;

use App\Enums\EstadoCotizacion;
use App\Enums\TipoMovimiento;
use App\Exceptions\OperacionTesoreriaRechazada;
use App\Http\Controllers\Concerns\RegresaABandeja;
use App\Http\Requests\CotizacionPagoRequest;
use App\Models\Cotizacion;
use App\Models\CotizacionPago;
use App\Models\Pedido;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use App\Services\Tesoreria\RegistradorMovimientos;
use App\Services\Ventas\CreadorVentaDeCotizacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CotizacionPagoController extends Controller
{
    use RegresaABandeja;

    public function __construct(
        private readonly RegistradorMovimientos $registrador,
        private readonly CreadorVentaDeCotizacion $ventas,
    ) {}

    /**
     * Las reglas se revisan otra vez con la cotización bloqueada: dos clics
     * seguidos no pueden generar un sobrepago ni un segundo anticipo. El pago
     * entra a su cuenta como ingreso en la misma transacción (bloqueo:
     * primero la cotización, después la cuenta).
     *
     * El primer pago decide qué nace (029): con un cliente que no es
     * distribuidor y algo de producción, la venta y su orden de trabajo; si
     * no, nada, y la cotización sigue su flujo (011). Con venta, la
     * cotización queda aceptada y la venta deriva su estado de estos pagos.
     */
    public function store(CotizacionPagoRequest $request, Cotizacion $cotizacion): RedirectResponse
    {
        $cuenta = $request->user()->cuentas()->findOrFail($request->validated('cuenta_id'));

        [$pago, $destino, $venta] = DB::transaction(function () use ($request, $cotizacion, $cuenta) {
            $bloqueada = Cotizacion::whereKey($cotizacion->id)->lockForUpdate()->firstOrFail();
            $tipo = $request->tipo();
            $motivo = $bloqueada->motivoRechazoPago($tipo, $request->validated('monto'));

            if ($motivo !== null) {
                throw ValidationException::withMessages(['monto' => $motivo])->errorBag('pago');
            }

            // Se decide antes de crear el pago: después ya no sería el primero.
            $destino = $bloqueada->destinoAlCobrar();

            if ($destino?->creaVenta() && $request->datosVenta() === []) {
                throw ValidationException::withMessages(['cliente_nombre' => 'Este pago creará la venta: recarga la página para capturar sus datos.'])->errorBag('pago');
            }

            $pago = $bloqueada->pagos()->create([
                'tipo' => $tipo,
                'fecha_pago' => $request->validated('fecha_pago'),
                'cuenta_id' => $cuenta->id,
                'monto' => $bloqueada->montoDePago($tipo, $request->validated('monto')),
            ]);
            $pago->setRelation('cotizacion', $bloqueada);
            $bloqueada->unsetRelation('pagos');

            try {
                $this->registrador->registrar($cuenta, TipoMovimiento::Ingreso, $pago->monto, $pago->fecha_pago->toDateString(), $pago->conceptoMovimiento(), $pago);
            } catch (OperacionTesoreriaRechazada $rechazo) {
                throw ValidationException::withMessages(['cuenta_id' => $rechazo->getMessage()])->errorBag('pago');
            }

            $venta = $destino?->creaVenta()
                ? $this->ventas->crear($bloqueada, $request->datosVenta(), $destino->creaOrden())
                : $this->ventaBloqueada($bloqueada);

            if ($venta !== null) {
                // La cotización se queda en aceptada; la venta lleva el estado del cobro.
                $venta->setRelation('cotizacion', $bloqueada);
                $venta->recalcularEstado();
                $venta->save();
            } else {
                if ($bloqueada->estado === EstadoCotizacion::Borrador) {
                    $bloqueada->estado = EstadoCotizacion::Enviada;
                }

                if (CalculadoraTotalesDocumento::centavos($bloqueada->saldoPendiente()) <= 0) {
                    $bloqueada->estado = EstadoCotizacion::Pagada;
                }
            }

            $bloqueada->save();

            return [$pago, $destino, $destino?->creaVenta() ? $venta : null];
        });

        $mensaje = $pago->tipo->etiqueta().' de $'.number_format((float) $pago->monto, 2).' registrado.';
        $enlaces = [];

        if ($venta !== null) {
            $conOrden = $venta->ordenTrabajo !== null;
            $mensaje .= " Se creó la venta {$venta->folio_formateado}".($conOrden ? ' y su orden de trabajo.' : '.');
            $enlaces['Ver venta'] = route('pedidos.show', $venta);

            if ($conOrden) {
                $enlaces['Ver orden de trabajo'] = route('pedidos.orden-trabajo.show', $venta);
            }
        } elseif ($destino?->razonSinVenta() !== null) {
            $mensaje .= ' '.$destino->razonSinVenta();
        }

        return redirect()->to($this->destinoCotizacion($request, $cotizacion))
            ->with('exito', $mensaje)
            ->with('exito_enlaces', $enlaces);
    }

    /**
     * Solo el último pago, y no en una cotización entregada. Se lleva su
     * ingreso de Tesorería (aunque la cuenta ya esté inactiva), salvo que la
     * cuenta quede en negativo. Si deja de cubrir el total, una pagada regresa
     * a enviada.
     *
     * Con venta que cobra aquí (029): no en una venta entregada ni facturada.
     * La venta y su orden se conservan aunque no quede ningún pago; la venta
     * recalcula su estado (vuelve a pendiente sin pagos).
     */
    public function destroy(Cotizacion $cotizacion, CotizacionPago $pago): RedirectResponse
    {
        Gate::authorize('operar', $cotizacion);

        return DB::transaction(function () use ($cotizacion, $pago) {
            $bloqueada = Cotizacion::whereKey($cotizacion->id)->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado === EstadoCotizacion::ProductoEntregado) {
                return back()->with('error', 'Los pagos de una cotización entregada no se eliminan.');
            }

            $venta = $this->ventaBloqueada($bloqueada);
            $motivoVenta = $venta === null ? null : $this->motivoNoEliminaPagoDeVenta($venta);

            if ($motivoVenta !== null) {
                return back()->with('error', $motivoVenta);
            }

            if ((int) $bloqueada->pagos()->max('id') !== $pago->id) {
                return back()->with('error', 'Solo se puede eliminar el último pago registrado.');
            }

            try {
                $this->registrador->eliminarDeDocumento($pago);
            } catch (OperacionTesoreriaRechazada $rechazo) {
                return back()->with('error', "No se puede eliminar el pago: la cuenta {$rechazo->cuenta->nombre} quedaría con saldo negativo.");
            }

            $pago->delete();
            $bloqueada->unsetRelation('pagos');

            if ($venta !== null) {
                $venta->setRelation('cotizacion', $bloqueada);
                $venta->recalcularEstado();
                $venta->save();
            }

            if ($bloqueada->estado === EstadoCotizacion::Pagada && CalculadoraTotalesDocumento::centavos($bloqueada->saldoPendiente()) > 0) {
                $bloqueada->estado = EstadoCotizacion::Enviada;
                $bloqueada->save();
            }

            return redirect()->route('cotizaciones.show', $cotizacion)->with('exito', 'Pago eliminado.');
        });
    }

    /**
     * Su venta que cobra aquí, bloqueada después de la cotización (el mismo
     * orden que la edición de la cotización y la entrega de la venta).
     */
    private function ventaBloqueada(Cotizacion $bloqueada): ?Pedido
    {
        $venta = $bloqueada->ventaQueCobraAqui();

        return $venta === null ? null : Pedido::whereKey($venta->id)->lockForUpdate()->first();
    }

    private function motivoNoEliminaPagoDeVenta(Pedido $venta): ?string
    {
        if ($venta->estaEntregado()) {
            return 'Los pagos de una venta entregada no se eliminan.';
        }

        $factura = $venta->facturaVigente ?? $venta->facturaDeLaCotizacion();

        return $factura === null
            ? null
            : "La venta {$venta->folio_formateado} ya tiene la factura {$factura->folioVisible()}: cancélala antes de quitar el pago.";
    }
}
