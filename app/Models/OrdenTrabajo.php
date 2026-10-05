<?php

namespace App\Models;

use App\Enums\EstadoOrdenTrabajo;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Orden de trabajo de una venta (022). Cliente, teléfono, folio y artículos
 * se leen de la venta; aquí solo vive lo propio: el color de tinta de cada
 * línea, la imagen del diseño y el estado.
 *
 * Sus artículos son pedido->lineasDeTrabajo(): en una venta que cobra en la
 * cotización, solo los de producción (029).
 *
 * Nada es asignable: pedido_id y estado los escribe el controlador y
 * avanzar(); imagen_ruta, solo GuardadorImagenWebp.
 */
class OrdenTrabajo extends Model
{
    protected $table = 'ordenes_trabajo';

    public const DIRECTORIO_IMAGENES = 'ordenes-trabajo';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'estado' => 'en_dibujo',
    ];

    /**
     * El archivo no lo borra la base de datos. Quien borra la venta borra
     * antes la orden con Eloquent para que esto corra (PedidoController), y
     * el archivo se va solo si la transacción se confirma.
     */
    protected static function booted(): void
    {
        static::deleted(function (self $orden) {
            if ($orden->imagen_ruta !== null) {
                DB::afterCommit(fn () => Storage::disk('local')->delete($orden->imagen_ruta));
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Pedido, $this>
     */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    /**
     * @return HasMany<OrdenTrabajoLinea, $this>
     */
    public function lineas(): HasMany
    {
        return $this->hasMany(OrdenTrabajoLinea::class);
    }

    /**
     * El renglón de color de una línea de la venta, si ya lo tiene.
     */
    public function colorDe(PedidoLinea $linea): ?OrdenTrabajoLinea
    {
        return $this->lineas->firstWhere('pedido_linea_id', $linea->id);
    }

    /**
     * Las líneas de trabajo de la venta que todavía no tienen color: las de
     * una orden que nació sola con el primer pago (029) y las que se
     * agregaron al editar la venta después de crear la orden.
     *
     * @return Collection<int, PedidoLinea>
     */
    public function lineasSinColor(): Collection
    {
        $conColor = $this->lineas->pluck('pedido_linea_id')->all();

        return $this->pedido->lineasDeTrabajo()->reject(fn (PedidoLinea $linea) => in_array($linea->id, $conColor, true))->values();
    }

    /**
     * Hasta que la venta se entrega; después queda solo para consulta.
     */
    public function esEditable(): bool
    {
        return ! $this->pedido->estaEntregado();
    }

    public function puedeAvanzar(): bool
    {
        return $this->motivoNoAvanza() === null;
    }

    public function motivoNoAvanza(): ?string
    {
        $sinColor = $this->lineasSinColor();

        return match (true) {
            $this->estado === EstadoOrdenTrabajo::Entregado => 'La orden ya se entregó.',
            $this->estado === EstadoOrdenTrabajo::Terminado => 'La orden ya está terminada: usa «Entregado».',
            ! $this->esEditable() => 'La venta ya se entregó.',
            $sinColor->isNotEmpty() => 'Falta el color de tinta de: '.$sinColor->pluck('descripcion')->join(', ').'.',
            default => null,
        };
    }

    /**
     * No guarda.
     */
    public function avanzar(): void
    {
        $this->estado = $this->estado->siguiente() ?? $this->estado;
    }

    /**
     * @return Attribute<bool, never>
     */
    protected function tieneImagen(): Attribute
    {
        return Attribute::get(fn (): bool => $this->imagen_ruta !== null);
    }

    /**
     * Parte al azar del nombre del archivo ("{id}-{version}.webp"), para que
     * un reemplazo cambie la URL y el navegador no muestre la imagen anterior.
     *
     * @return Attribute<string|null, never>
     */
    protected function imagenVersion(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->imagen_ruta === null
            ? null
            : Str::after(pathinfo($this->imagen_ruta, PATHINFO_FILENAME), '-'));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado' => EstadoOrdenTrabajo::class,
        ];
    }
}
