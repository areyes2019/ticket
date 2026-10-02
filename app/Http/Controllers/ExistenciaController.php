<?php

namespace App\Http\Controllers;

use App\Enums\MotivoMovimientoInventario;
use App\Http\Requests\AjusteExistenciaRequest;
use App\Http\Requests\ListadoExistenciasRequest;
use App\Http\Requests\ParametrosExistenciaRequest;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Existencia;
use App\Models\User;
use App\Services\Inventario\GeneradorOrdenesReposicion;
use App\Services\Inventario\RegistradorInventario;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * La bodega curada: solo los artículos que el usuario marcó "en
 * existencias". Las piezas solo las mueve RegistradorInventario.
 */
class ExistenciaController extends Controller
{
    public const POR_PAGINA = 15;

    /**
     * Listado con filtros, orden, totales del conjunto filtrado y el resumen de
     * lo que crearía "Generar órdenes de compra".
     */
    public function index(ListadoExistenciasRequest $request, GeneradorOrdenesReposicion $generador): View
    {
        return view('existencias.index', [
            ...$this->datosListado($request, $this->existencias($request)),
            'catalogos' => $request->user()->catalogos()->orderBy('nombre')->pluck('nombre', 'id')->all(),
            'proveedores' => $request->user()->proveedores()->orderBy('nombre_comercial')->pluck('nombre_comercial', 'id')->all(),
            'plan' => $generador->plan($request->user()),
        ]);
    }

    /**
     * Solo lo que cambia al buscar u ordenar, para la búsqueda dinámica (AJAX).
     */
    public function buscar(ListadoExistenciasRequest $request): View
    {
        $existencias = $this->existencias($request)->withPath(route('existencias.index'));

        return view('existencias._resultados', $this->datosListado($request, $existencias));
    }

    /**
     * Buscador del catálogo general: artículos propios que todavía no están en
     * existencias. Cada uno lleva a su ficha, donde se captura la cantidad.
     */
    public function agregar(Request $request): View
    {
        $texto = $request->string('q')->trim()->toString();

        $articulos = $texto === '' ? collect() : $request->user()->articulos()
            ->whereDoesntHave('existencia')
            ->where(fn (Builder $q) => $q->where('nombre', 'like', "%{$texto}%")->orWhere('modelo', 'like', "%{$texto}%"))
            ->with('catalogo')
            ->orderBy('modelo')
            ->limit(20)
            ->get();

        return view('existencias.agregar', ['texto' => $texto, 'articulos' => $articulos]);
    }

    /**
     * Ficha de cualquier artículo propio: sus números y acciones si está en
     * existencias; si no, el alta (diálogo abierto al cargar). El historial se
     * ve aunque el artículo ya no esté en existencias.
     */
    public function show(Articulo $articulo): View
    {
        Gate::authorize('view', $articulo);

        $articulo->load(['catalogo', 'proveedor', 'existencia']);

        return view('existencias.show', [
            'articulo' => $articulo,
            'fila' => $articulo->existencia,
            'tuvoFila' => $articulo->existencia === null && Existencia::onlyTrashed()->where('articulo_id', $articulo->id)->exists(),
            'movimientos' => $articulo->movimientosInventario()->with('documentable')->orderByDesc('id')->paginate(self::POR_PAGINA),
            'motivos' => MotivoMovimientoInventario::opcionesManuales(),
        ]);
    }

    /**
     * Ajuste manual o alta: fija la cantidad final y borra el faltante.
     */
    public function ajuste(AjusteExistenciaRequest $request, Articulo $articulo, RegistradorInventario $inventario): RedirectResponse
    {
        $esAlta = $articulo->existencia()->doesntExist();

        $inventario->ajustar($articulo, $request->cantidad(), $request->motivo(), $request->nota());

        return redirect()->route('existencias.show', $articulo)->with('exito', $esAlta
            ? "{$articulo->modelo} pasó a existencias con {$request->cantidad()} piezas."
            : "Existencia de {$articulo->modelo} ajustada a {$request->cantidad()}.");
    }

    /**
     * Mínimo y máximo. No genera movimiento: cambiar un umbral no mueve
     * piezas. Sin fila viva, 404.
     */
    public function parametros(ParametrosExistenciaRequest $request, Articulo $articulo): RedirectResponse
    {
        $fila = $articulo->existencia()->firstOrFail();
        $fila->forceFill($request->parametros())->save();

        return redirect()->route('existencias.show', $articulo)->with('exito', "Mínimo y máximo de {$articulo->modelo} guardados.");
    }

    /**
     * Quitar de existencias: borrado lógico de la fila. No se bloquea aunque
     * queden piezas; el historial se conserva.
     */
    public function destroy(Articulo $articulo, RegistradorInventario $inventario): RedirectResponse
    {
        Gate::authorize('update', $articulo);

        abort_if($articulo->existencia()->doesntExist(), 404);

        $inventario->quitar($articulo);

        return redirect()->route('existencias.index')->with('exito', "{$articulo->modelo} ya no está en existencias. Su historial se conserva.");
    }

    /**
     * @return LengthAwarePaginator<int, Existencia>
     */
    private function existencias(ListadoExistenciasRequest $request): LengthAwarePaginator
    {
        $consulta = $this->conjunto($request->user(), $request->filtros())->with('articulo.catalogo');
        $direccion = $request->direccion();

        match ($request->orden()) {
            'catalogo' => $consulta->orderBy(
                Catalogo::withTrashed()->select('nombre')->whereColumn('catalogos.id', 'articulos.catalogo_id'),
                $direccion
            ),
            'existencia' => $consulta->orderBy('existencias.existencia', $direccion),
            'faltante' => $consulta->orderBy('existencias.faltante_pendiente', $direccion),
            'minimo' => $consulta->orderBy('existencias.minimo', $direccion),
            // Mismas fórmulas que los totales: invertido y beneficio no son columnas.
            'invertido' => $consulta->orderByRaw('existencias.existencia * articulos.costo_con_descuento '.$direccion),
            'beneficio' => $consulta->orderByRaw('existencias.existencia * (articulos.precio_unitario_sin_iva - articulos.costo_con_descuento) '.$direccion),
            default => $consulta->orderBy('articulos.modelo', $direccion),
        };

        // Desempate para que la paginación sea estable.
        return $consulta->orderBy('existencias.id', $direccion)
            ->paginate(self::POR_PAGINA)
            ->withQueryString();
    }

    /**
     * Filas vivas del usuario con los filtros aplicados. Sin $conPorPedir, ignora
     * el filtro "solo por pedir" (para el contador junto al interruptor).
     *
     * @param  array{q: string, catalogo: int|null, proveedor: int|null, por_pedir: bool}  $filtros
     * @return Builder<Existencia>
     */
    private function conjunto(User $user, array $filtros, bool $conPorPedir = true): Builder
    {
        return Existencia::delUsuario($user)
            ->when($filtros['q'] !== '', fn (Builder $q) => $q->where(fn (Builder $texto) => $texto
                ->where('articulos.nombre', 'like', "%{$filtros['q']}%")
                ->orWhere('articulos.modelo', 'like', "%{$filtros['q']}%")))
            ->when($filtros['catalogo'] !== null, fn (Builder $q) => $q->where('articulos.catalogo_id', $filtros['catalogo']))
            ->when($filtros['proveedor'] !== null, fn (Builder $q) => $q->where('articulos.proveedor_id', $filtros['proveedor']))
            ->when($conPorPedir && $filtros['por_pedir'], fn (Builder $q) => $q->soloPorPedir());
    }

    /**
     * Las cuatro cifras sobre el conjunto filtrado completo, nunca sobre la
     * página: una sola consulta agregada. Los alias llevan prefijo suma_ para
     * que ningún accesor del modelo los eclipse.
     *
     * @param  array{q: string, catalogo: int|null, proveedor: int|null, por_pedir: bool}  $filtros
     * @return array{unidades: int, invertido: float, beneficio: float, total: float}
     */
    private function totales(User $user, array $filtros): array
    {
        $sumas = $this->conjunto($user, $filtros)->toBase()->select([
            DB::raw('COALESCE(SUM(existencias.existencia), 0) AS suma_unidades'),
            DB::raw('COALESCE(SUM(existencias.existencia * articulos.costo_con_descuento), 0) AS suma_invertido'),
            DB::raw('COALESCE(SUM(existencias.existencia * (articulos.precio_unitario_sin_iva - articulos.costo_con_descuento)), 0) AS suma_beneficio'),
        ])->first();

        $invertido = round((float) $sumas->suma_invertido, 2);
        $beneficio = round((float) $sumas->suma_beneficio, 2);

        return [
            'unidades' => (int) $sumas->suma_unidades,
            'invertido' => $invertido,
            'beneficio' => $beneficio,
            'total' => round($invertido + $beneficio, 2),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, Existencia>  $existencias
     * @return array<string, mixed>
     */
    private function datosListado(ListadoExistenciasRequest $request, LengthAwarePaginator $existencias): array
    {
        $filtros = $request->filtros();

        return [
            'existencias' => $existencias,
            'filtros' => $filtros,
            'orden' => $request->orden(),
            'direccion' => $request->direccion(),
            'parametros' => $request->parametros(),
            'totales' => $this->totales($request->user(), $filtros),
            'porPedir' => $this->conjunto($request->user(), $filtros, conPorPedir: false)->soloPorPedir()->count(),
        ];
    }
}
