<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tokens y costo estimado de una llamada a Claude (041, regla 72).
 */
#[Fillable([
    'user_id',
    'whatsapp_conversacion_id',
    'modelo',
    'tokens_entrada',
    'tokens_salida',
    'tokens_cache_lectura',
    'tokens_cache_escritura',
    'costo_usd',
])]
class WhatsappUsoClaude extends Model
{
    protected $table = 'whatsapp_uso_claude';

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'costo_usd' => 'decimal:6',
        ];
    }
}
