<?php

namespace App\Models;

use App\Enums\TipoCuenta;
use Database\Factories\CuentaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Lugar donde se guarda el dinero del negocio (caja, banco, PayPal…).
 *
 * saldo_actual no es asignable: lo escribe solo RegistradorMovimientos (y el
 * alta, igual al inicial). saldo_inicial no cambia después del alta; se
 * corrige con un ajuste.
 */
#[Fillable(['nombre', 'tipo', 'saldo_inicial', 'activa'])]
class Cuenta extends Model
{
    /** @use HasFactory<CuentaFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'activa' => true,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Movimiento, $this>
     */
    public function movimientos(): HasMany
    {
        return $this->hasMany(Movimiento::class);
    }

    /**
     * Usa movimientos_count cuando el listado lo precargó con withCount().
     */
    public function tieneMovimientos(): bool
    {
        if (array_key_exists('movimientos_count', $this->attributes)) {
            return $this->attributes['movimientos_count'] > 0;
        }

        return $this->movimientos()->exists();
    }

    /**
     * @param  Builder<self>  $consulta
     */
    #[Scope]
    protected function activas(Builder $consulta): void
    {
        $consulta->where('activa', true);
    }

    /**
     * La caja que el mostrador preselecciona al cobrar (033, 034): la cuenta
     * de efectivo más antigua (menor id) entre las que se ofrecen; null si no
     * hay ninguna de efectivo.
     *
     * @param  Collection<int, self>  $cuentas
     */
    public static function cajaEntre(Collection $cuentas): ?self
    {
        return $cuentas->where('tipo', TipoCuenta::Efectivo)->sortBy('id')->first();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoCuenta::class,
            'saldo_inicial' => 'decimal:2',
            'saldo_actual' => 'decimal:2',
            'activa' => 'boolean',
        ];
    }
}
