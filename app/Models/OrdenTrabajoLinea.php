<?php

namespace App\Models;

use App\Enums\ColorTinta;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El color de tinta de una línea de la venta. Se va con la línea: al editar
 * la venta, ConservadorColores lo pasa a la línea nueva equivalente.
 */
#[Fillable([
    'pedido_linea_id',
    'color_tinta',
    'color_tinta_otro',
])]
class OrdenTrabajoLinea extends Model
{
    /**
     * @return BelongsTo<OrdenTrabajo, $this>
     */
    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenTrabajo::class, 'orden_trabajo_id');
    }

    /**
     * @return BelongsTo<PedidoLinea, $this>
     */
    public function pedidoLinea(): BelongsTo
    {
        return $this->belongsTo(PedidoLinea::class);
    }

    /**
     * "Azul", o lo que escribió el usuario en "Otro".
     */
    public function colorTexto(): string
    {
        return $this->color_tinta === ColorTinta::Otro
            ? (string) $this->color_tinta_otro
            : $this->color_tinta->etiqueta();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'color_tinta' => ColorTinta::class,
        ];
    }
}
