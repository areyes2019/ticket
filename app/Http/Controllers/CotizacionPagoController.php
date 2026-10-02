<?php

namespace App\Http\Controllers;

use App\Enums\EstadoCotizacion;
use App\Enums\TipoMovimiento;
use App\Exceptions\OperacionTesoreriaRechazada;
use App\Http\Controllers\Concerns\RegresaABandeja;
use App\Http\Requests\CotizacionPagoRequest;
use App\Models\Cotizacion;
use App\Models\CotizacionPago;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use App\Services\Tesoreria\RegistradorMovimientos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CotizacionPagoController extends Controller
{
    use RegresaABandeja;

    public function __construct(private readonly RegistradorMovimientos $registrador) {}

    /**
     * Las reglas se revisan otra vez con la cotización bloqueada: dos clics
     * seguidos no pueden generar un sobrepago ni un segundo anticipo. El pago
     * entra a su cuenta como ingreso en la misma transacción (bloqueo:
     * primero la cotización, después la cuenta).
     */
    public function store(CotizacionPagoRequest $request, Cotizacion $cotizacion): RedirectResponse
    {
        $cuenta = $request->user()->cuentas()->findOrFail($request->validated('cuenta_id'));

        $pago = DB::transaction(function () use ($request, $cotizacion, $cuenta) {
            $bloqueada = Cotizacion::whereKey($cotizacion->id)->lockForUpdate()->firstOrFail();
            $tipo = $request->tipo();
            $motivo = $bloqueada->motivoRechazoPago($tipo, $request->validated('monto'));

            if ($motivo !== null) {
                throw ValidationException::withMessages(['monto' => $motivo])->errorBag('pago');
            }

            $pago = $bloqueada->pagos()->create([
                'tipo' => $tipo,
                'fecha_pago' => $request->validated('fecha_pago'),
                'cuenta_id' => $cuenta->id,
                'monto' => $bloqueada->montoDePago($tipo, $request->validated('monto')),
            ]);
            $pago->setRelation('cotizacion', $bloqueada);

            try {
                $this->registrador->registrar($cuenta, TipoMovimiento::Ingreso, $pago->monto, $pago->fecha_pago->toDateString(), $pago->conceptoMovimiento(), $pago);
            } catch (OperacionTesoreriaRechazada $rechazo) {
                throw ValidationException::withMessages(['cuenta_id' => $rechazo->getMessage()])->errorBag('pago');
            }

            if (CalculadoraTotalesDocumento::centavos($bloqueada->saldoPendiente()) <= 0) {
                $bloqueada->estado = EstadoCotizacion::Pagada;
                $bloqueada->save();
            }

            return $pago;
        });

        return redirect()->to($this->destinoCotizacion($request, $cotizacion))
            ->with('exito', $pago->tipo->etiqueta().' de $'.number_format((float) $pago->monto, 2).' registrado.');
    }

    /**
     * Solo el último pago, y no en una cotización entregada. Se lleva su
     * ingreso de Tesorería (aunque la cuenta ya esté inactiva), salvo que la
     * cuenta quede en negativo. Si deja de cubrir el total, una pagada regresa
     * a enviada.
     */
    public function destroy(Cotizacion $cotizacion, CotizacionPago $pago): RedirectResponse
    {
        Gate::authorize('operar', $cotizacion);

        return DB::transaction(function () use ($cotizacion, $pago) {
            $bloqueada = Cotizacion::whereKey($cotizacion->id)->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado === EstadoCotizacion::ProductoEntregado) {
                return back()->with('error', 'Los pagos de una cotización entregada no se eliminan.');
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

            if ($bloqueada->estado === EstadoCotizacion::Pagada && CalculadoraTotalesDocumento::centavos($bloqueada->saldoPendiente()) > 0) {
                $bloqueada->estado = EstadoCotizacion::Enviada;
                $bloqueada->save();
            }

            return redirect()->route('cotizaciones.show', $cotizacion)->with('exito', 'Pago eliminado.');
        });
    }
}
