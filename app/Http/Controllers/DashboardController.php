<?php

namespace App\Http\Controllers;

use App\Models\Cotizacion;
use App\Models\Factura;
use App\Support\Demo\BandejaCorreoDemo;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Cuántas cotizaciones y facturas recientes muestra cada lista del inicio.
     */
    public const RECIENTES = 25;

    /**
     * Inicio: cotizaciones y facturas recientes con la vista previa del
     * documento pedido en la URL (?cotizacion= o ?factura=) o, si no hay, de la
     * primera cotización, y la ventana para crear una cotización ahí mismo.
     * Además, la bandeja de correo de demostración (013),
     * que se abre desde el menú de aplicaciones.
     */
    public function __invoke(Request $request, BandejaCorreoDemo $bandeja): View
    {
        $user = $request->user();

        $cotizaciones = $user->cotizaciones()
            ->with('cliente')
            ->withCount('pagos')
            ->withSum('pagos', 'monto')
            ->withExists('facturaVigente')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECIENTES)
            ->get();

        $facturas = $user->facturas()
            ->with('cliente')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECIENTES)
            ->get();

        // Un id ajeno o inexistente se ignora, como en la bandeja de cotizaciones.
        $abierta = match (true) {
            $request->filled('factura') => $user->facturas()->find($request->integer('factura')),
            $request->filled('cotizacion') => $user->cotizaciones()->find($request->integer('cotizacion')),
            default => null,
        } ?? $cotizaciones->first() ?? $facturas->first();

        $datosVisor = match (true) {
            $abierta instanceof Cotizacion => [
                ...CotizacionController::datosAcciones($abierta),
                'cotizacion' => $abierta->load(['cliente', 'lineas', 'pagos', 'facturaVigente', 'venta.facturaVigente']),
            ],
            $abierta instanceof Factura => ['factura' => $abierta->load(['cliente', 'lineas'])],
            default => [],
        };

        return view('dashboard', [
            'cotizaciones' => $cotizaciones,
            'facturas' => $facturas,
            'abierta' => $abierta,
            'datosVisor' => $datosVisor,
            // La ventana "Nueva cotización"; tras un error de validación vuelve
            // abierta con lo capturado.
            'nuevaCotizacion' => CotizacionController::datosFormulario($request),
            'abrirNuevaCotizacion' => $request->old('origen') === 'dashboard',
            'carpetas' => $bandeja->carpetas(),
            'etiquetas' => $bandeja->etiquetas(),
            'correos' => $bandeja->correos(),
        ]);
    }
}
