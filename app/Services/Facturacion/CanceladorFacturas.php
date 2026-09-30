<?php

namespace App\Services\Facturacion;

use App\Enums\EstadoCancelacion;
use App\Enums\MotivoCancelacion;
use App\Models\Factura;
use Illuminate\Support\Facades\Log;

/**
 * La cancelación ante el SAT puede no ser inmediata: facturapi.io responde un
 * cancellation_status y la factura solo pasa a cancelada con accepted. Lo
 * intermedio se refresca al volver a abrir el detalle.
 */
class CanceladorFacturas
{
    public function __construct(private FacturapiCliente $facturapi) {}

    /**
     * La sustitución se manda con el UUID fiscal de la sustituta (así lo
     * documentó la implementación remota).
     *
     * @throws FacturapiException
     */
    public function cancelar(Factura $factura, MotivoCancelacion $motivo, ?Factura $sustituta = null): EstadoCancelacion
    {
        $respuesta = $this->facturapi->cancelarFactura(
            $factura->facturapi_invoice_id,
            $motivo,
            $motivo->requiereSustituta() ? $sustituta?->uuid_fiscal : null,
            $factura->id,
        );

        $factura->motivo_cancelacion = $motivo;
        $factura->factura_sustituta_id = $motivo->requiereSustituta() ? $sustituta?->id : null;

        $estado = $this->estadoDe($respuesta, $factura) ?? EstadoCancelacion::Pendiente;
        $factura->aplicarEstadoCancelacion($estado);

        return $estado;
    }

    /**
     * Vuelve a consultar una cancelación en curso. false si facturapi.io no
     * respondió: la pantalla sigue con el último estado conocido.
     */
    public function refrescar(Factura $factura): bool
    {
        try {
            $respuesta = $this->facturapi->consultarFactura($factura->facturapi_invoice_id, $factura->id);
        } catch (FacturapiException) {
            return false;
        }

        $estado = $this->estadoDe($respuesta, $factura);

        if ($estado !== null && $estado !== $factura->estado_cancelacion) {
            $factura->aplicarEstadoCancelacion($estado);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $respuesta
     */
    private function estadoDe(array $respuesta, Factura $factura): ?EstadoCancelacion
    {
        if (($respuesta['status'] ?? null) === 'canceled') {
            return EstadoCancelacion::Aceptada;
        }

        $valor = $respuesta['cancellation_status'] ?? null;
        $estado = is_string($valor) ? EstadoCancelacion::tryFrom($valor) : null;

        if ($estado === null && $valor !== null) {
            Log::warning('facturapi', ['operacion' => 'cancellation_status desconocido', 'factura_id' => $factura->id, 'valor' => $valor]);
        }

        return $estado;
    }
}
