<?php

namespace App\Models;

use App\Enums\ClaveConfiguracion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Almacén clave→valor por usuario. Sin fila, la clave vale su texto por
 * defecto; con la fila en null, el usuario la dejó vacía a propósito.
 */
#[Fillable([
    'clave',
    'valor',
])]
class Configuracion extends Model
{
    protected $table = 'configuraciones';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function valor(User $user, ClaveConfiguracion $clave): ?string
    {
        $fila = $user->configuraciones()->where('clave', $clave->value)->first();

        return $fila === null ? $clave->valorPorDefecto() : $fila->valor;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clave' => ClaveConfiguracion::class,
        ];
    }
}
