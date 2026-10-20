<?php

namespace App\Models;

use App\Enums\DecisionFacturaReembolso;
use App\Enums\EstadoCancelacion;
use App\Enums\EstadoFactura;
use App\Enums\MotivoCancelacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Reembolso de una venta o de una cotización sin venta (038): un solo
 * reembolso por documento, que no se deshace. Sus egresos en Tesorería lo
 * tienen como documentable. Guarda qué se hizo con la factura (adición 7);
 * factura_folio sobrevive al descarte de una factura sin timbrar.
 *
 * Nada es asignable: lo escribe solo el Reembolsador.
 */
class Reembolso extends Model
{
    /**
     * @return MorphTo<Model, $this>
     */
    public function reembolsable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Factura, $this>
     */
    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class);
    }

    /**
     * @return MorphMany<Movimiento, $this>
     */
    public function movimientos(): MorphMany
    {
        return $this->morphMany(Movimiento::class, 'documentable')->orderBy('id');
    }

    /**
     * "Venta PED-0043" o "Cotización COT-0012".
     */
    public function etiquetaDocumento(): string
    {
        return ($this->reembolsable instanceof Pedido ? 'Venta ' : 'Cotización ').$this->reembolsable->folio_formateado;
    }

    public function urlDocumento(): string
    {
        return $this->reembolsable instanceof Pedido
            ? route('pedidos.show', $this->reembolsable)
            : route('cotizaciones.show', $this->reembolsable);
    }

    /**
     * Lo que pasó con la factura, para el recuadro del reembolso; null sin
     * factura.
     */
    public function resumenFactura(): ?string
    {
        $folio = $this->factura?->folioVisible() ?? $this->factura_folio;

        return match ($this->factura_decision) {
            DecisionFacturaReembolso::Cancelar => "Factura {$folio}: se pidió su cancelación"
                .($this->motivo_cancelacion ? " ({$this->motivo_cancelacion->value} – {$this->motivo_cancelacion->descripcion()})" : '')
                .'. Estado: '.$this->estadoCancelacionTexto().'.',
            DecisionFacturaReembolso::Conservar => "Factura {$folio}: se dejó vigente.",
            DecisionFacturaReembolso::Descartar => "Factura {$folio}: todavía no se timbraba y se descartó.",
            null => null,
        };
    }

    /**
     * Aviso de la factura que sigue vigente: rechazada (regla 25) o
     * conservada a propósito (regla 26). null en los demás casos.
     */
    public function avisoFactura(): ?string
    {
        if ($this->factura === null || $this->factura->estado !== EstadoFactura::Timbrada) {
            return null;
        }

        return match (true) {
            $this->factura_decision === DecisionFacturaReembolso::Conservar => "Reembolsada con factura vigente {$this->factura->folioVisible()}.",
            $this->cancelacionRechazada() => "La factura {$this->factura->folioVisible()} sigue vigente: la cancelación fue rechazada.",
            default => null,
        };
    }

    /**
     * El botón "Volver a cancelar factura" (adición 8).
     */
    public function puedeReintentarCancelacion(): bool
    {
        return $this->cancelacionRechazada() && $this->factura->puedeCancelarse();
    }

    private function cancelacionRechazada(): bool
    {
        return $this->factura_decision === DecisionFacturaReembolso::Cancelar
            && $this->factura?->estado === EstadoFactura::Timbrada
            && $this->factura->estado_cancelacion === EstadoCancelacion::Rechazada;
    }

    private function estadoCancelacionTexto(): string
    {
        return match (true) {
            $this->factura === null => 'sin datos',
            $this->factura->estado === EstadoFactura::Cancelada => 'Cancelada',
            $this->factura->cancelacionEnCurso() => 'Pendiente de que el cliente la acepte',
            $this->factura->estado_cancelacion === EstadoCancelacion::Rechazada => 'Rechazada',
            default => 'Vigente',
        };
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'factura_decision' => DecisionFacturaReembolso::class,
            'motivo_cancelacion' => MotivoCancelacion::class,
        ];
    }
}
