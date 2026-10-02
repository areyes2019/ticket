<?php

namespace App\Models;

use App\Enums\TipoPago;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Registro interno de un pago: no es CFDI ni pasa por ningún PAC. Cada pago
 * entra a una cuenta de Tesorería como un ingreso automático.
 */
#[Fillable([
    'tipo',
    'fecha_pago',
    'monto',
    'cuenta_id',
])]
class CotizacionPago extends Model
{
    /**
     * Registrar o eliminar un pago cuenta como movimiento de la cotización.
     *
     * @var list<string>
     */
    protected $touches = ['cotizacion'];

    /**
     * @return BelongsTo<Cotizacion, $this>
     */
    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    /**
     * @return BelongsTo<Cuenta, $this>
     */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    /**
     * El ingreso que generó en Tesorería.
     *
     * @return MorphOne<Movimiento, $this>
     */
    public function movimiento(): MorphOne
    {
        return $this->morphOne(Movimiento::class, 'documentable');
    }

    /**
     * Concepto del ingreso automático: "Anticipo de Cotización COT-0012".
     */
    public function conceptoMovimiento(): string
    {
        return $this->tipo->etiqueta().' de Cotización '.$this->cotizacion->folio_formateado;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoPago::class,
            'fecha_pago' => 'date',
            'monto' => 'decimal:2',
        ];
    }
}
