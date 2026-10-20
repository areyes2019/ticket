<?php

namespace App\Http\Controllers;

use App\Enums\DecisionFacturaReembolso;
use App\Enums\EstadoFactura;
use App\Enums\MotivoMovimientoInventario;
use App\Exceptions\ReembolsoNoPermitidoException;
use App\Http\Requests\ReembolsoRequest;
use App\Models\Cotizacion;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\Reembolso;
use App\Services\Ventas\Reembolsador;
use Illuminate\Http\RedirectResponse;

/**
 * Reembolso de una venta o de una cotización sin venta (038). Las reglas
 * viven en el Reembolsador; aquí solo se arma la respuesta.
 */
class ReembolsoController extends Controller
{
    public function __construct(private readonly Reembolsador $reembolsador) {}

    public function pedido(ReembolsoRequest $request, Pedido $pedido): RedirectResponse
    {
        return $this->reembolsar($request, $pedido, route('pedidos.show', $pedido));
    }

    public function cotizacion(ReembolsoRequest $request, Cotizacion $cotizacion): RedirectResponse
    {
        return $this->reembolsar($request, $cotizacion, route('cotizaciones.show', $cotizacion));
    }

    private function reembolsar(ReembolsoRequest $request, Pedido|Cotizacion $documento, string $regreso): RedirectResponse
    {
        try {
            $reembolso = $this->reembolsador->reembolsar($documento, $request->validated('motivo'), $request->decisionFactura(), $request->motivoCancelacion());
        } catch (ReembolsoNoPermitidoException $error) {
            return redirect($regreso)->with('error', $error->getMessage());
        }

        return redirect($regreso)->with('exito', $this->mensajeExito($documento, $reembolso));
    }

    /**
     * Regla 39.
     */
    private function mensajeExito(Pedido|Cotizacion $documento, Reembolso $reembolso): string
    {
        $nombre = $documento instanceof Pedido ? 'Venta' : 'Cotización';
        $regresaron = MovimientoInventario::whereMorphedTo('documentable', $documento)
            ->where('motivo', MotivoMovimientoInventario::ReembolsoVenta)
            ->exists();

        $mensaje = "{$nombre} {$documento->folio_formateado} reembolsada. Salieron $".number_format((float) $reembolso->monto, 2).' de Tesorería'
            .($regresaron ? ' y sus artículos regresaron a existencias.' : '.');

        $factura = $reembolso->factura?->refresh();
        $folio = $factura?->folioVisible() ?? $reembolso->factura_folio;

        return match ($reembolso->factura_decision) {
            DecisionFacturaReembolso::Cancelar => $mensaje." Se pidió la cancelación de la factura {$folio}: "
                .($factura?->estado === EstadoFactura::Cancelada ? 'Cancelada.' : 'Pendiente de que el cliente la acepte.'),
            DecisionFacturaReembolso::Conservar => $mensaje." La factura {$folio} quedó vigente.",
            DecisionFacturaReembolso::Descartar => $mensaje." Se descartó la factura {$folio}.",
            null => $mensaje,
        };
    }
}
