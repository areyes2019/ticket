<?php

namespace App\Models;

use App\Enums\RegimenFiscal;
use Database\Factories\ClienteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use PhpCfdi\Rfc\Rfc;

#[Fillable([
    'rfc',
    'razon_social',
    'regimen_fiscal',
    'codigo_postal_fiscal',
    'nombre_comercial',
    'nombre_contacto',
    'correo',
    'telefono',
    'direccion_comercial',
    'descuento_permanente',
    'es_distribuidor',
])]
class Cliente extends Model
{
    /** @use HasFactory<ClienteFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Tope del descuento permanente (023), en porcentaje.
     */
    public const DESCUENTO_MAXIMO = 50;

    /**
     * Columnas que se pueden filtrar desde el listado.
     */
    public const FILTROS = ['razon_social', 'nombre_comercial', 'nombre_contacto', 'rfc'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'descuento_permanente' => '0.00',
        'es_distribuidor' => false,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Cotizacion, $this>
     */
    public function cotizaciones(): HasMany
    {
        return $this->hasMany(Cotizacion::class);
    }

    /**
     * @return HasMany<Factura, $this>
     */
    public function facturas(): HasMany
    {
        return $this->hasMany(Factura::class);
    }

    /**
     * Aplica los filtros del listado (coincidencia parcial, combinados con Y).
     * Los filtros vacíos se ignoran.
     *
     * @param  Builder<self>  $consulta
     * @param  array<string, string>  $filtros
     */
    #[Scope]
    protected function filtrar(Builder $consulta, array $filtros): void
    {
        foreach (self::FILTROS as $columna) {
            $termino = trim($filtros[$columna] ?? '');

            if ($termino === '') {
                continue;
            }

            if ($columna === 'rfc') {
                $termino = strtoupper(preg_replace('/\s+/', '', $termino));
            }

            $consulta->where($columna, 'like', "%{$termino}%");
        }
    }

    /**
     * Una sola caja de texto contra razón social, nombre comercial o RFC
     * (combinados con O), para las tarjetas del mostrador (033). Vacía no filtra.
     *
     * @param  Builder<self>  $consulta
     */
    #[Scope]
    protected function buscarTexto(Builder $consulta, string $texto): void
    {
        $termino = trim($texto);

        if ($termino === '') {
            return;
        }

        $rfc = strtoupper(preg_replace('/\s+/', '', $termino));

        $consulta->where(fn (Builder $grupo) => $grupo
            ->where('razon_social', 'like', "%{$termino}%")
            ->orWhere('nombre_comercial', 'like', "%{$termino}%")
            ->orWhere('rfc', 'like', "%{$rfc}%"));
    }

    public function tieneDescuentoPermanente(): bool
    {
        return (float) $this->descuento_permanente > 0;
    }

    /**
     * "15%" o "12.5%" (sin ceros de sobra); "—" sin descuento.
     */
    public function descuentoPermanenteTexto(): string
    {
        return $this->tieneDescuentoPermanente() ? self::porcentajeTexto($this->descuento_permanente).'%' : '—';
    }

    /**
     * "15.00" → "15", "12.50" → "12.5".
     */
    public static function porcentajeTexto(float|string $porcentaje): string
    {
        return rtrim(rtrim(number_format((float) $porcentaje, 2, '.', ''), '0'), '.');
    }

    /**
     * "fisica" o "moral" según el RFC; null para los RFC genéricos.
     *
     * @return Attribute<?string, never>
     */
    protected function tipoPersona(): Attribute
    {
        return Attribute::get(function (): ?string {
            $rfc = Rfc::parseOrNull((string) $this->rfc);

            if ($rfc === null || $rfc->isGeneric() || $rfc->isForeign()) {
                return null;
            }

            return $rfc->isFisica() ? 'fisica' : 'moral';
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'regimen_fiscal' => RegimenFiscal::class,
            'descuento_permanente' => 'decimal:2',
            'es_distribuidor' => 'boolean',
        ];
    }
}
