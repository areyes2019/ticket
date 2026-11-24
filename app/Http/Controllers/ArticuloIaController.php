<?php

namespace App\Http\Controllers;

use App\Models\Articulo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * "Artículos IA" (047): la lista que el agente de ventas consulta primero. Es
 * el único lugar que escribe articulos.para_ia.
 */
class ArticuloIaController extends Controller
{
    public const POR_PAGINA = 25;

    public const LIMITE_AGREGAR = 20;

    public function index(Request $request): View
    {
        return view('articulos-ia.index', $this->datosListado($request));
    }

    /**
     * Solo lo que cambia al buscar, para la búsqueda dinámica (AJAX).
     */
    public function buscar(Request $request): View
    {
        $datos = $this->datosListado($request);
        $datos['articulos']->withPath(route('articulos-ia.index'));

        return view('articulos-ia._resultados', $datos);
    }

    /**
     * Buscador del catálogo general: artículos propios que todavía no están en
     * la lista.
     */
    public function agregar(Request $request): View
    {
        $texto = $this->texto($request);

        $articulos = $texto === '' ? collect() : $request->user()->articulos()
            ->where('para_ia', false)
            ->where(fn (Builder $q) => $q->where('nombre', 'like', "%{$texto}%")->orWhere('modelo', 'like', "%{$texto}%"))
            ->with('catalogo')
            ->orderBy('modelo')
            ->limit(self::LIMITE_AGREGAR)
            ->get();

        return view('articulos-ia.agregar', ['texto' => $texto, 'articulos' => $articulos]);
    }

    public function store(Articulo $articulo): RedirectResponse
    {
        return $this->marcar($articulo, true, "{$articulo->modelo} se agregó a Artículos IA.");
    }

    public function destroy(Articulo $articulo): RedirectResponse
    {
        return $this->marcar($articulo, false, "{$articulo->modelo} se quitó de Artículos IA.");
    }

    /**
     * Repetir la acción no es error: la marca queda igual (regla 25).
     */
    private function marcar(Articulo $articulo, bool $paraIa, string $mensaje): RedirectResponse
    {
        Gate::authorize('update', $articulo);

        // Sin save(): los eventos de guardado recalculan precios y no hace falta.
        Articulo::whereKey($articulo->id)->update(['para_ia' => $paraIa]);

        return redirect()->route('articulos-ia.index')->with('exito', $mensaje);
    }

    /**
     * @return array{texto: string, articulos: LengthAwarePaginator<int, Articulo>, hayMarcados: bool}
     */
    private function datosListado(Request $request): array
    {
        $texto = $this->texto($request);

        $articulos = $request->user()->articulos()
            ->paraIa()
            ->conDisponibilidad()
            ->when($texto !== '', fn (Builder $q) => $q->where(fn (Builder $g) => $g->where('nombre', 'like', "%{$texto}%")->orWhere('modelo', 'like', "%{$texto}%")))
            ->orderBy('modelo')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        return [
            'texto' => $texto,
            'articulos' => $articulos,
            'hayMarcados' => $texto === '' ? $articulos->total() > 0 : $request->user()->articulos()->paraIa()->exists(),
        ];
    }

    private function texto(Request $request): string
    {
        return mb_substr($request->string('q')->trim()->toString(), 0, 100);
    }
}
