<?php

namespace App\Models;

use App\Enums\FormaPago;
use App\Enums\TipoPago;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro interno de un pago: no es CFDI ni pasa por ningún PAC.
 */
#[Fillable([
    'tipo',
    'fecha_pago',
    'monto',
    'forma_pago',
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
            'forma_pago' => FormaPago::class,
        ];
    }
}
