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
 *
 * Primero busca en "Artículos IA" y, si ahí no hay nada, en todo el catálogo
 * en la misma llamada (047, adición 2). Cada artículo dice si está en
 * existencia o sobre pedido, nunca cuántas piezas hay (adición 3).
 */
class BuscarArticulos extends HerramientaBase
{
    public const LIMITE = 10;

    public const LISTA_IA = 'ia';

    public const LISTA_CATALOGO = 'todo_el_catalogo';

    public function nombre(): string
    {
        return 'buscar_articulos';
    }

    public function descripcion(): string
    {
        return 'Busca productos del negocio por nombre o modelo (por ejemplo "sello 4x2", "autoentintable", "tinta azul"). '
            .'Busca primero en la lista principal del negocio; si ahí no hay nada, busca sola en todo el catálogo. '
            .'Regresa hasta 10 artículos con su id, nombre, modelo, precio público con IVA en pesos y disponibilidad '
            .'("en_existencia" o "sobre_pedido"), y en "lista" dice dónde los encontró. '
            .'Es la única fuente de productos y precios: úsala siempre antes de mencionar un precio.';
    }

    public function esquema(): array
    {
        return self::objeto([
            'texto' => ['type' => 'string', 'description' => 'Palabras a buscar en el nombre o el modelo.'],
            'todo_el_catalogo' => ['type' => 'boolean', 'description' => 'Ponlo en true solo si el cliente pide algo muy especial que no apareció o rechazó lo que le ofreciste. Normalmente false.'],
        ]);
    }

    public function ejecutar(ContextoTurno $turno, array $entrada): ResultadoHerramienta
    {
        $datos = $this->validar($entrada, [
            'texto' => ['required', 'string', 'max:100'],
            'todo_el_catalogo' => ['sometimes', 'nullable', 'boolean'],
        ]);

        if ($datos instanceof ResultadoHerramienta) {
            return $datos;
        }

        $palabras = array_values(array_filter(preg_split('/\s+/', trim($datos['texto'])) ?: [], fn (string $p) => mb_strlen($p) >= 2));

        if ($palabras === []) {
            return ResultadoHerramienta::error('Escribe al menos una palabra de dos letras.');
        }

        $lista = self::LISTA_IA;
        $enCatalogo = ! empty($datos['todo_el_catalogo']);

        if (! $enCatalogo) {
            [$total, $todas] = $this->contar($turno, $palabras, true);
            $enCatalogo = $total === 0;
        }

        if ($enCatalogo) {
            $lista = self::LISTA_CATALOGO;
            [$total, $todas] = $this->contar($turno, $palabras, false);
        }

        $articulos = $this->porRelevancia($this->consulta($turno, $palabras, $todas, $lista === self::LISTA_IA), $palabras)
            ->conDisponibilidad()
            ->limit(self::LIMITE)
            ->get();

        $datosResultado = [
            'lista' => $lista,
            'articulos' => $articulos->map(fn (Articulo $a) => [
                'id' => $a->id,
                'nombre' => $a->nombre,
                'modelo' => $a->modelo,
                'precio_con_iva' => $a->precio_unitario_con_iva,
                'disponibilidad' => $a->en_existencia ? 'en_existencia' : 'sobre_pedido',
            ])->values()->all(),
            'total_encontrados' => $total,
        ];

        $avisos = [];

        // Pasó solo a la lista general: hay lista principal y nada coincidió.
        if ($lista === self::LISTA_CATALOGO && empty($datos['todo_el_catalogo']) && $turno->user()->articulos()->paraIa()->exists()) {
            $avisos[] = 'Nada de la lista principal coincide; estos artículos son del catálogo completo.';
        }

        if ($total > $articulos->count()) {
            $avisos[] = "Hay {$total} artículos que coinciden y solo se muestran {$articulos->count()}. "
                .'Si no ves lo que busca el cliente, busca otra vez con palabras más precisas (por ejemplo "sello fechador" en vez de "fechador").';
        }

        if ($avisos !== []) {
            $datosResultado['aviso'] = implode(' ', $avisos);
        }

        $donde = $lista === self::LISTA_IA ? 'lista IA' : 'todo el catálogo';

        return ResultadoHerramienta::ok($datosResultado, 'consultó artículos: "'.$datos['texto'].'" ('.$donde.', '.$total.')');
    }

    /**
     * Cuántos coinciden con todas las palabras y, si ninguno y hay varias, con
     * cualquiera.
     *
     * @param  list<string>  $palabras
     * @return array{0: int, 1: bool} total y si se usaron todas las palabras
     */
    private function contar(ContextoTurno $turno, array $palabras, bool $soloIa): array
    {
        $total = $this->consulta($turno, $palabras, true, $soloIa)->count();

        if ($total === 0 && count($palabras) > 1) {
            return [$this->consulta($turno, $palabras, false, $soloIa)->count(), false];
        }

        return [$total, true];
    }

    /**
     * @param  list<string>  $palabras
     */
    private function consulta(ContextoTurno $turno, array $palabras, bool $todas, bool $soloIa): Builder|Relation
    {
        return $turno->user()->articulos()
            ->when($soloIa, fn (Builder $q) => $q->paraIa())
            ->where(function (Builder $consulta) use ($palabras, $todas) {
                foreach ($palabras as $palabra) {
                    $condicion = fn (Builder $q) => $q->where('nombre', 'like', "%{$palabra}%")->orWhere('modelo', 'like', "%{$palabra}%");
                    $todas ? $consulta->where($condicion) : $consulta->orWhere($condicion);
                }
            });
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
