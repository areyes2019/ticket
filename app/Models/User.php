<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\EstadoUsuario;
use App\Enums\Rol;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'rol' => 'usuario',
        'estado' => 'activo',
    ];

    public function esAdministrador(): bool
    {
        return $this->rol === Rol::Administrador;
    }

    public function estaActivo(): bool
    {
        return $this->estado === EstadoUsuario::Activo;
    }

    /**
     * @return HasMany<IntentoAcceso, $this>
     */
    public function intentosAcceso(): HasMany
    {
        return $this->hasMany(IntentoAcceso::class);
    }

    /**
     * @return HasMany<Cliente, $this>
     */
    public function clientes(): HasMany
    {
        return $this->hasMany(Cliente::class);
    }

    /**
     * @return HasMany<Proveedor, $this>
     */
    public function proveedores(): HasMany
    {
        return $this->hasMany(Proveedor::class);
    }

    /**
     * @return HasMany<Catalogo, $this>
     */
    public function catalogos(): HasMany
    {
        return $this->hasMany(Catalogo::class);
    }

    /**
     * @return HasMany<Articulo, $this>
     */
    public function articulos(): HasMany
    {
        return $this->hasMany(Articulo::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'rol' => Rol::class,
            'estado' => EstadoUsuario::class,
        ];
    }
}
