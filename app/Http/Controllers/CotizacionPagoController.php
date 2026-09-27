<?php

namespace App\Http\Controllers;

use App\Enums\EstadoCotizacion;
use App\Http\Requests\CotizacionPagoRequest;
use App\Models\Cotizacion;
use App\Models\CotizacionPago;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CotizacionPagoController extends Controller
{
    /**
     * Las reglas se revisan otra vez con la cotización bloqueada: dos clics
     * seguidos no pueden generar un sobrepago ni un segundo anticipo.
     */
    public function store(CotizacionPagoRequest $request, Cotizacion $cotizacion): RedirectResponse
    {
        $pago = DB::transaction(function () use ($request, $cotizacion) {
            $bloqueada = Cotizacion::whereKey($cotizacion->id)->lockForUpdate()->firstOrFail();
            $tipo = $request->tipo();
            $motivo = $bloqueada->motivoRechazoPago($tipo, $request->validated('monto'));

            if ($motivo !== null) {
                throw ValidationException::withMessages(['monto' => $motivo])->errorBag('pago');
            }

            $pago = $bloqueada->pagos()->create([
                'tipo' => $tipo,
                'fecha_pago' => $request->validated('fecha_pago'),
                'forma_pago' => $request->validated('forma_pago'),
                'monto' => $bloqueada->montoDePago($tipo, $request->validated('monto')),
            ]);

            if (CalculadoraTotalesDocumento::centavos($bloqueada->saldoPendiente()) <= 0) {
                $bloqueada->estado = EstadoCotizacion::Pagada;
                $bloqueada->save();
            }

            return $pago;
        });

        return redirect()->route('cotizaciones.show', $cotizacion)
            ->with('exito', $pago->tipo->etiqueta().' de $'.number_format((float) $pago->monto, 2).' registrado.');
    }

    /**
     * Solo el último pago, y no en una cotización entregada. Si deja de
     * cubrir el total, una pagada regresa a enviada.
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

            $pago->delete();

            if ($bloqueada->estado === EstadoCotizacion::Pagada && CalculadoraTotalesDocumento::centavos($bloqueada->saldoPendiente()) > 0) {
                $bloqueada->estado = EstadoCotizacion::Enviada;
                $bloqueada->save();
            }

            return redirect()->route('cotizaciones.show', $cotizacion)->with('exito', 'Pago eliminado.');
        });
    }
}
