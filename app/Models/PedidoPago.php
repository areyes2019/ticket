<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Pago de un pedido: el monto que se recibió, en la cuenta que se eligió.
 * Cada uno tiene su ingreso en Tesorería (documentable). Sin tipo: puede
 * haber varios y ninguno depende de otro. registrado_al_entregar lo escribe
 * solo la entrega por escaneo.
 */
#[Fillable([
    'cuenta_id',
    'fecha_pago',
    'monto',
])]
class PedidoPago extends Model
{
    /**
     * Registrar o eliminar un pago cuenta como movimiento del pedido.
     *
     * @var list<string>
     */
    protected $touches = ['pedido'];

    /**
     * @return BelongsTo<Pedido, $this>
     */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    /**
     * @return BelongsTo<Cuenta, $this>
     */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    /**
     * @return MorphOne<Movimiento, $this>
     */
    public function movimiento(): MorphOne
    {
        return $this->morphOne(Movimiento::class, 'documentable');
    }

    /**
     * Concepto no editable del ingreso en Tesorería.
     */
    public function conceptoMovimiento(): string
    {
        return ($this->registrado_al_entregar ? 'Saldo al entregar de Pedido ' : 'Pago de Pedido ').$this->pedido->folio_formateado;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_pago' => 'date',
            'monto' => 'decimal:2',
            'registrado_al_entregar' => 'boolean',
        ];
    }
}
