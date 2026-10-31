<?php

namespace App\Http\Controllers;

use App\Enums\EstadoOrdenTrabajo;
use App\Support\Demo\BandejaCorreoDemo;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Cuántas órdenes recientes muestra la lista del inicio.
     */
    public const RECIENTES = 25;

    /**
     * Inicio: órdenes de trabajo recientes, con la vista previa de la orden
     * pedida en la URL (?ot=) o, si no hay, de la más reciente.
     * Además, la bandeja de correo de demostración (013),
     * que se abre desde el menú de aplicaciones.
     */
    public function __invoke(Request $request, BandejaCorreoDemo $bandeja): View
    {
        $user = $request->user();

        // Todas menos las entregadas (022, corrección 1) y las canceladas por
        // un reembolso (038), que salen de la lista; ?ot= todavía las abre. La
        // venta y sus líneas, para el folio, el cliente y "Falta color" sin una
        // consulta por fila.
        $ordenesTrabajo = $user->ordenesTrabajo()
            ->whereNotIn('estado', [EstadoOrdenTrabajo::Entregado->value, EstadoOrdenTrabajo::Cancelada->value])
            ->with(['pedido.lineas.articulo.catalogo', 'lineas'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECIENTES)
            ->get();

        // Un id ajeno o inexistente se ignora.
        $abierta = ($request->filled('ot') ? $user->ordenesTrabajo()->find($request->integer('ot')) : null)
            ?? $ordenesTrabajo->first();

        return view('dashboard', [
            'ordenesTrabajo' => $ordenesTrabajo,
            'abierta' => $abierta,
            'datosVisor' => $abierta ? OrdenTrabajoController::datosVistaPrevia($abierta->pedido) : [],
            'carpetas' => $bandeja->carpetas(),
            'etiquetas' => $bandeja->etiquetas(),
            'correos' => $bandeja->correos(),
        ]);
    }
}
