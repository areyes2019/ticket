<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plantilla aprobada en Meta (041, regla 10). Se crean y editan en el
 * administrador de WhatsApp; aquí solo se guardan para elegirlas.
 */
#[Fillable([
    'nombre',
    'idioma',
    'categoria',
    'cuerpo',
    'variables',
    'estado',
])]
class WhatsappPlantilla extends Model
{
    protected $table = 'whatsapp_plantillas';

    /**
     * @return BelongsTo<WhatsappNumero, $this>
     */
    public function numero(): BelongsTo
    {
        return $this->belongsTo(WhatsappNumero::class, 'whatsapp_numero_id');
    }

    /**
     * El cuerpo con {{1}}, {{2}}… sustituidas.
     *
     * @param  list<string>  $valores
     */
    public function texto(array $valores): string
    {
        return preg_replace_callback('/\{\{(\d+)\}\}/', fn (array $m) => $valores[(int) $m[1] - 1] ?? $m[0], $this->cuerpo);
    }

    public static function contarVariables(string $cuerpo): int
    {
        preg_match_all('/\{\{(\d+)\}\}/', $cuerpo, $coincidencias);

        return $coincidencias[1] === [] ? 0 : max(array_map('intval', $coincidencias[1]));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'variables' => 'integer',
        ];
    }
}
