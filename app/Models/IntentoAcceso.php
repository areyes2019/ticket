<?php

namespace App\Models;

use App\Enums\ResultadoAcceso;
use Database\Factories\IntentoAccesoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('intentos_acceso')]
#[Fillable(['email', 'user_id', 'resultado', 'ip_address', 'navegador', 'dispositivo', 'user_agent'])]
class IntentoAcceso extends Model
{
    /** @use HasFactory<IntentoAccesoFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resultado' => ResultadoAcceso::class,
        ];
    }
}
