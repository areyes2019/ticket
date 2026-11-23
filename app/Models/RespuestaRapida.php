<?php

namespace App\Models;

use Database\Factories\RespuestaRapidaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Texto guardado que se inserta en el chat de WhatsApp tecleando "/atajo"
 * (043). Es del negocio: todos los usuarios ven la misma lista. El texto
 * puede llevar {nombre} y {fecha}, que llena el navegador al insertarlo.
 */
#[Fillable(['atajo', 'texto'])]
class RespuestaRapida extends Model
{
    /** @use HasFactory<RespuestaRapidaFactory> */
    use HasFactory;

    /**
     * Eloquent inferiría "respuesta_rapidas".
     */
    protected $table = 'respuestas_rapidas';

    public const MAX_ATAJO = 30;

    public const MAX_TEXTO = 4000;

    /**
     * "/Horário " → "horario" (regla 2). Los espacios de en medio se quedan
     * para que la validación los rechace con su mensaje.
     */
    public static function normalizarAtajo(string $atajo): string
    {
        return Str::lower(Str::ascii(ltrim(trim($atajo), '/')));
    }

    /**
     * @param  Builder<self>  $consulta
     */
    #[Scope]
    protected function ordenadas(Builder $consulta): void
    {
        $consulta->orderBy('atajo');
    }
}
