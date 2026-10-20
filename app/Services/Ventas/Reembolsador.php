<?php

namespace App\Services\Ventas;

use App\Enums\DecisionFacturaReembolso;
use App\Enums\EstadoCotizacion;
use App\Enums\EstadoFactura;
use App\Enums\EstadoOrdenTrabajo;
use App\Enums\EstadoPedido;
use App\Enums\MotivoCancelacion;
use App\Enums\MotivoMovimientoInventario;
use App\Enums\TipoMovimiento;
use App\Exceptions\CuentaInactivaException;
use App\Exceptions\OperacionTesoreriaRechazada;
use App\Exceptions\ReembolsoNoPermitidoException;
use App\Models\Cotizacion;
use App\Models\CotizacionPago;
use App\Models\Factura;
use App\Models\Pedido;
use App\Models\PedidoPago;
use App\Models\Reembolso;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use App\Services\Facturacion\CanceladorFacturas;
use App\Services\Facturacion\FacturapiException;
use App\Services\Inventario\RegistradorInventario;
use App\Services\Tesoreria\RegistradorMovimientos;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Única vía para reembolsar una venta o una cotización sin venta (038):
 *
 * 1. revisión previa, sin escribir: que se pueda reembolsar y que cada
 *    cuenta esté activa y tenga saldo (adición 6);
 * 2. si hay factura timbrada y se eligió cancelarla, la pide al SAT. Va
 *    fuera de la transacción, como en Facturación: no se puede deshacer, por
 *    eso va después de la revisión;
 * 3. en una transacción (adición 1), con el documento bloqueado y revisado
 *    otra vez (adición 2): egresos, inventario, descarte de la factura sin
 *    timbrar, orden de trabajo y estados.
 */
class Reembolsador
{
    public function __construct(
        private readonly RegistradorMovimientos $movimientos,
        private readonly RegistradorInventario $inventario,
        private readonly CanceladorFacturas $cancelador,
    ) {}

    /**
     * $decision solo cuenta con una factura timbrada sin cancelación en
     * curso: es lo que eligió el usuario (Cancelar o Conservar).
     *
     * @throws ReembolsoNoPermitidoException
     */
    public function reembolsar(Pedido|Cotizacion $documento, string $motivo, ?DecisionFacturaReembolso $decision = null, ?MotivoCancelacion $motivoSat = null): Reembolso
    {
        $this->cargar($documento);
        $this->revisarAntes($documento);

        $factura = $documento->facturaParaReembolso();
        $decisionFinal = $this->decisionPara($factura, $decision);
        $pidioCancelacion = false;

        if ($decisionFinal === DecisionFacturaReembolso::Cancelar && $factura->puedeCancelarse()) {
            $motivoSat ??= MotivoCancelacion::NoSeLlevoACabo;

            try {
                $this->cancelador->cancelar($factura, $motivoSat);
            } catch (FacturapiException $error) {
                throw new ReembolsoNoPermitidoException("No se pudo pedir la cancelación de la factura {$factura->folioVisible()}: {$error->getMessage()} No se reembolsó nada; inténtalo de nuevo.");
            }

            $pidioCancelacion = true;
        }

        try {
            return DB::transaction(fn () => $this->aplicar($documento, $motivo, $factura, $decisionFinal));
        } catch (ReembolsoNoPermitidoException|OperacionTesoreriaRechazada|UniqueConstraintViolationException $error) {
            $mensaje = $this->mensajeDeFalla($documento, $error);

            throw new ReembolsoNoPermitidoException($pidioCancelacion
                ? "Se pidió la cancelación de la factura {$factura->folioVisible()}, pero el reembolso no se hizo: {$mensaje} Corrige y reembolsa de nuevo; la factura ya no se volverá a cancelar."
                : $mensaje);
        }
    }

    /**
     * Regla 27: todo lo que puede hacer fallar el reembolso, antes de llamar
     * al SAT. La misma revisión se repite dentro de la transacción.
     *
     * @throws ReembolsoNoPermitidoException
     */
    private function revisarAntes(Pedido|Cotizacion $documento): void
    {
        $motivo = $documento->motivoNoReembolsable();

        if ($motivo !== null) {
            throw new ReembolsoNoPermitidoException($motivo);
        }

        foreach ($documento->devolucionesPorCuenta() as ['cuenta' => $cuenta, 'monto' => $monto]) {
            $cuenta->refresh();

            if (! $cuenta->activa) {
                throw new ReembolsoNoPermitidoException($this->mensajeCuentaInactiva($cuenta->nombre));
            }

            if (CalculadoraTotalesDocumento::centavos($cuenta->saldo_actual) < CalculadoraTotalesDocumento::centavos($monto)) {
                throw new ReembolsoNoPermitidoException($this->mensajeSinSaldo($cuenta->nombre, $monto));
            }
        }
    }

    /**
     * Lo que se guarda: sale de la factura, no solo del formulario.
     */
    private function decisionPara(?Factura $factura, ?DecisionFacturaReembolso $elegida): ?DecisionFacturaReembolso
    {
        return match (true) {
            $factura === null => null,
            $factura->estado === EstadoFactura::Borrador, $factura->estado === EstadoFactura::Pendiente => DecisionFacturaReembolso::Descartar,
            $factura->cancelacionEnCurso() => DecisionFacturaReembolso::Cancelar,
            $elegida === DecisionFacturaReembolso::Cancelar, $elegida === DecisionFacturaReembolso::Conservar => $elegida,
            default => throw new LogicException('Con factura timbrada hay que decidir si se cancela.'),
        };
    }

    /**
     * Dentro de la transacción. Bloqueo: primero la cotización y después la
     * venta, el mismo orden que la edición de la cotización y la entrega.
     */
    private function aplicar(Pedido|Cotizacion $documento, string $motivo, ?Factura $factura, ?DecisionFacturaReembolso $decision): Reembolso
    {
        [$bloqueado, $cotizacion] = $this->bloquear($documento);
        $this->cargar($bloqueado);

        $motivoNo = $bloqueado->motivoNoReembolsable();

        if ($motivoNo !== null) {
            throw new ReembolsoNoPermitidoException($motivoNo);
        }

        $factura?->refresh();

        $reembolso = new Reembolso;
        $reembolso->user_id = $bloqueado->user_id;
        $reembolso->reembolsable()->associate($bloqueado);
        $reembolso->monto = $bloqueado->montoReembolsable();
        $reembolso->motivo = $motivo;
        $reembolso->factura_id = $decision === DecisionFacturaReembolso::Descartar ? null : $factura?->id;
        $reembolso->factura_folio = $factura?->folioVisible();
        $reembolso->factura_decision = $decision;
        $reembolso->motivo_cancelacion = $decision === DecisionFacturaReembolso::Cancelar ? $factura->motivo_cancelacion : null;
        $reembolso->save();

        $hoy = now(config('app.zona_negocio'))->toDateString();
        $concepto = 'Reembolso de '.($bloqueado instanceof Pedido ? 'Venta ' : 'Cotización ').$bloqueado->folio_formateado;

        foreach ($bloqueado->pagosParaReembolso() as $pago) {
            /** @var PedidoPago|CotizacionPago $pago */
            $this->movimientos->registrar($pago->cuenta, TipoMovimiento::Egreso, $pago->monto, $hoy, $concepto, $reembolso);
        }

        $this->inventario->revertirDocumento($bloqueado, MotivoMovimientoInventario::ReembolsoVenta);

        if ($decision === DecisionFacturaReembolso::Descartar) {
            $factura->delete();
        }

        if ($bloqueado instanceof Pedido) {
            $bloqueado->ordenTrabajo()->update(['estado' => EstadoOrdenTrabajo::Cancelada->value]);
            $bloqueado->estado = EstadoPedido::Reembolsado;
            $bloqueado->save();
        }

        $cotizacion?->forceFill(['estado' => EstadoCotizacion::Reembolsada])->save();

        return $reembolso->setRelation('reembolsable', $bloqueado);
    }

    /**
     * @return array{0: Pedido|Cotizacion, 1: Cotizacion|null} el documento y la cotización que también queda reembolsada
     */
    private function bloquear(Pedido|Cotizacion $documento): array
    {
        if ($documento instanceof Cotizacion) {
            $cotizacion = Cotizacion::whereKey($documento->id)->lockForUpdate()->firstOrFail();

            return [$cotizacion, $cotizacion];
        }

        $cotizacion = $documento->cotizacion_id === null
            ? null
            : Cotizacion::whereKey($documento->cotizacion_id)->lockForUpdate()->first();
        $pedido = Pedido::whereKey($documento->id)->lockForUpdate()->firstOrFail();

        if ($cotizacion !== null) {
            $pedido->setRelation('cotizacion', $cotizacion);
        }

        return [$pedido, $cotizacion];
    }

    private function cargar(Pedido|Cotizacion $documento): void
    {
        $documento->load($documento instanceof Pedido
            ? ['pagos.cuenta', 'cotizacion.pagos.cuenta', 'cotizacion.facturaVigente', 'facturaVigente', 'ordenTrabajo']
            : ['pagos.cuenta', 'facturaVigente', 'venta']);
    }

    private function mensajeDeFalla(Pedido|Cotizacion $documento, ReembolsoNoPermitidoException|OperacionTesoreriaRechazada|UniqueConstraintViolationException $error): string
    {
        if ($error instanceof UniqueConstraintViolationException) {
            return $documento instanceof Pedido ? 'Esta venta ya se reembolsó.' : 'Esta cotización ya se reembolsó.';
        }

        if ($error instanceof CuentaInactivaException) {
            return $this->mensajeCuentaInactiva($error->cuenta->nombre);
        }

        if ($error instanceof OperacionTesoreriaRechazada) {
            $monto = $documento->devolucionesPorCuenta()->firstWhere('cuenta.id', $error->cuenta->id)['monto'] ?? null;

            return $monto === null ? $error->getMessage() : $this->mensajeSinSaldo($error->cuenta->nombre, $monto);
        }

        return $error->getMessage();
    }

    private function mensajeSinSaldo(string $cuenta, string $monto): string
    {
        return "La cuenta {$cuenta} no tiene saldo suficiente para devolver $".number_format((float) $monto, 2).'.';
    }

    private function mensajeCuentaInactiva(string $cuenta): string
    {
        return "La cuenta {$cuenta} está inactiva. Actívala para hacer el reembolso.";
    }
}
