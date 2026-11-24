<?php

namespace App\Services\Whatsapp\Herramientas;

use App\Models\Articulo;
use App\Services\Whatsapp\ContextoTurno;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Ventas (041, reglas 34 a 36): hasta 10 artículos del catálogo de la cuenta
 * con su precio público con IVA. Nunca costos, proveedor, utilidad ni precio
 * de distribuidor.
 */
class BuscarArticulos extends HerramientaBase
{
    public const LIMITE = 10;

    public function nombre(): string
    {
        return 'buscar_articulos';
    }

    public function descripcion(): string
    {
        return 'Busca productos en el catálogo del negocio por nombre o modelo (por ejemplo "sello 4x2", "autoentintable", "tinta azul"). '
            .'Regresa hasta 10 artículos con su id, nombre, modelo y precio público con IVA en pesos. '
            .'Es la única fuente de productos y precios: úsala siempre antes de mencionar un precio.';
    }

    public function esquema(): array
    {
        return self::objeto([
            'texto' => ['type' => 'string', 'description' => 'Palabras a buscar en el nombre o el modelo.'],
        ]);
    }

    public function ejecutar(ContextoTurno $turno, array $entrada): ResultadoHerramienta
    {
        $datos = $this->validar($entrada, ['texto' => ['required', 'string', 'max:100']]);

        if ($datos instanceof ResultadoHerramienta) {
            return $datos;
        }

        $palabras = array_values(array_filter(preg_split('/\s+/', trim($datos['texto'])) ?: [], fn (string $p) => mb_strlen($p) >= 2));

        if ($palabras === []) {
            return ResultadoHerramienta::error('Escribe al menos una palabra de dos letras.');
        }

        $consulta = fn (bool $todas) => $turno->user()->articulos()
            ->where(function (Builder $consulta) use ($palabras, $todas) {
                foreach ($palabras as $palabra) {
                    $condicion = fn (Builder $q) => $q->where('nombre', 'like', "%{$palabra}%")->orWhere('modelo', 'like', "%{$palabra}%");
                    $todas ? $consulta->where($condicion) : $consulta->orWhere($condicion);
                }
            });

        $todas = true;
        $total = $consulta(true)->count();

        if ($total === 0 && count($palabras) > 1) {
            $todas = false;
            $total = $consulta(false)->count();
        }

        $articulos = $this->porRelevancia($consulta($todas), $palabras)->limit(self::LIMITE)->get();

        $datosResultado = [
            'articulos' => $articulos->map(fn (Articulo $a) => [
                'id' => $a->id,
                'nombre' => $a->nombre,
                'modelo' => $a->modelo,
                'precio_con_iva' => $a->precio_unitario_con_iva,
            ])->values()->all(),
            'total_encontrados' => $total,
        ];

        if ($total > $articulos->count()) {
            $datosResultado['aviso'] = "Hay {$total} artículos que coinciden y solo se muestran {$articulos->count()}. "
                .'Si no ves lo que busca el cliente, busca otra vez con palabras más precisas (por ejemplo "sello fechador" en vez de "fechador").';
        }

        return ResultadoHerramienta::ok($datosResultado, 'consultó artículos: "'.$datos['texto'].'" ('.$total.')');
    }

    /**
     * Primero los artículos cuyo nombre trae las palabras más cerca del inicio
     * ("Sello fechador 4x2" antes que "Almohadilla para sello fechador"), luego
     * los nombres más cortos y al final el orden alfabético.
     *
     * @param  list<string>  $palabras
     */
    private function porRelevancia(Builder|Relation $consulta, array $palabras): Builder|Relation
    {
        $posiciones = [];
        $valores = [];

        foreach ($palabras as $palabra) {
            $posiciones[] = 'CASE WHEN INSTR(LOWER(nombre), ?) = 0 THEN 1000 ELSE INSTR(LOWER(nombre), ?) END';
            $valores[] = mb_strtolower($palabra);
            $valores[] = mb_strtolower($palabra);
        }

        return $consulta
            ->orderByRaw('('.implode(' + ', $posiciones).')', $valores)
            ->orderByRaw('LENGTH(nombre)')
            ->orderBy('nombre');
    }
}
