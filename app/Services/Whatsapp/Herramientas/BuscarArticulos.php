<?php

namespace App\Services\Whatsapp\Herramientas;

use App\Models\Articulo;
use App\Services\Whatsapp\ContextoTurno;
use Illuminate\Database\Eloquent\Builder;

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

        $buscar = fn (bool $todas) => $turno->user()->articulos()
            ->where(function (Builder $consulta) use ($palabras, $todas) {
                foreach ($palabras as $palabra) {
                    $condicion = fn (Builder $q) => $q->where('nombre', 'like', "%{$palabra}%")->orWhere('modelo', 'like', "%{$palabra}%");
                    $todas ? $consulta->where($condicion) : $consulta->orWhere($condicion);
                }
            })
            ->orderBy('nombre')
            ->limit(self::LIMITE)
            ->get();

        $articulos = $buscar(true);

        if ($articulos->isEmpty() && count($palabras) > 1) {
            $articulos = $buscar(false);
        }

        return ResultadoHerramienta::ok([
            'articulos' => $articulos->map(fn (Articulo $a) => [
                'id' => $a->id,
                'nombre' => $a->nombre,
                'modelo' => $a->modelo,
                'precio_con_iva' => $a->precio_unitario_con_iva,
            ])->values()->all(),
        ], 'consultó artículos: "'.$datos['texto'].'" ('.$articulos->count().')');
    }
}
