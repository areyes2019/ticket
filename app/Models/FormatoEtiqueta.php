<?php

namespace App\Models;

use App\Services\Etiquetas\MedidasPlanilla;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Un formato de planilla para las etiquetas de producción (031): nombre y las
 * seis medidas de MedidasPlanilla. Uno solo por usuario es el predeterminado;
 * lo escribe solo marcarPredeterminado().
 */
#[Fillable([
    'nombre',
    'ancho_mm',
    'alto_mm',
    'separacion_horizontal_mm',
    'separacion_vertical_mm',
    'margen_superior_mm',
    'margen_izquierdo_mm',
])]
class FormatoEtiqueta extends Model
{
    protected $table = 'formatos_etiqueta';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'es_predeterminado' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ancho_mm' => 'decimal:1',
            'alto_mm' => 'decimal:1',
            'separacion_horizontal_mm' => 'decimal:1',
            'separacion_vertical_mm' => 'decimal:1',
            'margen_superior_mm' => 'decimal:1',
            'margen_izquierdo_mm' => 'decimal:1',
            'es_predeterminado' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function medidas(): MedidasPlanilla
    {
        return MedidasPlanilla::desdeMilimetros(array_map(
            fn (string $campo) => $this->{$campo.'_mm'},
            array_combine(array_keys(MedidasPlanilla::CAMPOS), array_keys(MedidasPlanilla::CAMPOS))
        ));
    }

    public function asignarMedidas(MedidasPlanilla $medidas): void
    {
        foreach ($medidas->toArray() as $campo => $milimetros) {
            $this->{$campo.'_mm'} = $milimetros;
        }
    }

    /**
     * Desmarca los demás formatos del usuario y marca este.
     */
    public function marcarPredeterminado(): void
    {
        DB::transaction(function () {
            static::where('user_id', $this->user_id)->whereKeyNot($this->getKey())->update(['es_predeterminado' => false]);

            $this->es_predeterminado = true;
            $this->save();
        });
    }

    /**
     * Copia con el nombre libre siguiente: "Nombre (copia)", "(copia 2)"…
     * La copia no es predeterminada.
     */
    public function duplicar(): self
    {
        $existentes = static::where('user_id', $this->user_id)->pluck('nombre')->all();
        $base = mb_substr($this->nombre, 0, 60 - mb_strlen(' (copia 999)'));

        $nombre = "{$base} (copia)";
        for ($n = 2; in_array($nombre, $existentes, true); $n++) {
            $nombre = "{$base} (copia {$n})";
        }

        $copia = $this->replicate(['es_predeterminado']);
        $copia->nombre = $nombre;
        $copia->es_predeterminado = false;
        $copia->save();

        return $copia;
    }
}
