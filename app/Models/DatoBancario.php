<?php

namespace App\Models;

use Database\Factories\DatoBancarioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Cuenta a la que el cliente paga (027), impresa en la cotización. Es del
 * negocio, una lista para toda la instalación, y no tiene nada que ver con las
 * Cuentas de Tesorería: no tiene saldo ni movimientos.
 *
 * orden lo asigna el modelo al crear (al final de la lista) y lo cambia solo
 * DatoBancarioController::mover. logo_ruta la escribe solo GuardadorImagenWebp.
 */
#[Fillable(['nombre_banco', 'beneficiario', 'numero_cuenta', 'tarjeta', 'clabe', 'visible_en_cotizaciones'])]
class DatoBancario extends Model
{
    /** @use HasFactory<DatoBancarioFactory> */
    use HasFactory;

    /**
     * Eloquent inferiría "dato_bancarios".
     */
    protected $table = 'datos_bancarios';

    public const DIRECTORIO_LOGOS = 'datos-bancarios';

    /**
     * El logo se guarda ya reducido a icono: lado largo en puntos.
     */
    public const LADO_LOGO = 64;

    public const TAMANO_LOGO_KB = 2048;

    /**
     * Datos que se copian a la foto congelada de la cotización.
     */
    public const CAMPOS_FOTO = ['nombre_banco', 'beneficiario', 'numero_cuenta', 'tarjeta', 'clabe', 'logo_ruta'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'visible_en_cotizaciones' => true,
    ];

    protected static function booted(): void
    {
        static::creating(function (DatoBancario $dato) {
            $dato->orden ??= (int) self::query()->max('orden') + 1;
        });
    }

    #[Scope]
    protected function ordenados(Builder $consulta): void
    {
        $consulta->orderBy('orden')->orderBy('id');
    }

    /**
     * Los que se imprimen en las cotizaciones nuevas, en su orden.
     */
    #[Scope]
    protected function paraCotizacion(Builder $consulta): void
    {
        $consulta->where('visible_en_cotizaciones', true)->ordenados();
    }

    /**
     * @return array<string, string|null>
     */
    public function foto(): array
    {
        return $this->only(self::CAMPOS_FOTO);
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function tieneLogo(): Attribute
    {
        return Attribute::get(fn (): bool => $this->logo_ruta !== null);
    }

    /**
     * Parte al azar del nombre del archivo: va en la URL del logo para que un
     * reemplazo se vea sin vaciar la caché del navegador.
     *
     * @return Attribute<string|null, never>
     */
    protected function logoVersion(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->logo_ruta === null
            ? null
            : Str::after(pathinfo($this->logo_ruta, PATHINFO_FILENAME), '-'));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visible_en_cotizaciones' => 'boolean',
            'orden' => 'integer',
        ];
    }
}
