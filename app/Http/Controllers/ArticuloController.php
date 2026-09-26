<?php

namespace App\Http\Controllers;

use App\Enums\ObjetoImpuesto;
use App\Http\Requests\ArticuloRequest;
use App\Http\Requests\ListadoArticulosRequest;
use App\Models\Articulo;
use App\Models\SatClaveProdServ;
use App\Models\SatClaveUnidad;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ArticuloController extends Controller
{
    /**
     * Página completa del listado, con filtros, orden y página de la URL.
     */
    public function index(ListadoArticulosRequest $request): View
    {
        return view('articulos.index', $this->datosListado($request, $this->articulos($request)));
    }

    /**
     * Solo lo que cambia al buscar u ordenar, para la búsqueda dinámica (AJAX).
     * Los enlaces de página apuntan al listado completo para que se puedan
     * abrir sin JavaScript o compartir.
     */
    public function buscar(ListadoArticulosRequest $request): View
    {
        $articulos = $this->articulos($request)->withPath(route('articulos.index'));

        return view('articulos._resultados', $this->datosListado($request, $articulos));
    }

    public function create(Request $request): View
    {
        return view('articulos.crear', $this->datosFormulario($request));
    }

    public function store(ArticuloRequest $request): RedirectResponse
    {
        $request->user()->articulos()->create($request->validated());

        return redirect()->route('articulos.index')->with('exito', 'Artículo creado.');
    }

    public function edit(Request $request, Articulo $articulo): View
    {
        Gate::authorize('update', $articulo);

        return view('articulos.editar', $this->datosFormulario($request, $articulo));
    }

    /**
     * ArticuloRequest ya verificó que el artículo es del usuario.
     */
    public function update(ArticuloRequest $request, Articulo $articulo): RedirectResponse
    {
        $articulo->update($request->validated());

        return redirect()->route('articulos.index')->with('exito', 'Artículo actualizado.');
    }

    /**
     * Borrado lógico.
     *
     * Regla de negocio pendiente: no se debe eliminar un artículo usado en
     * líneas de factura. Se verificará aquí cuando exista el módulo de facturación.
     */
    public function destroy(Articulo $articulo): RedirectResponse
    {
        Gate::authorize('delete', $articulo);

        $articulo->delete();

        return redirect()->route('articulos.index')->with('exito', 'Artículo eliminado.');
    }

    /**
     * @return LengthAwarePaginator<int, Articulo>
     */
    private function articulos(ListadoArticulosRequest $request): LengthAwarePaginator
    {
        return $request->user()->articulos()
            ->with(['proveedor', 'catalogo'])
            ->filtrar($request->filtros())
            ->ordenar($request->orden(), $request->direccion())
            ->paginate($request->porPagina())
            ->withQueryString();
    }

    /**
     * @param  LengthAwarePaginator<int, Articulo>  $articulos
     * @return array<string, mixed>
     */
    private function datosListado(ListadoArticulosRequest $request, LengthAwarePaginator $articulos): array
    {
        return [
            'articulos' => $articulos,
            'filtros' => $request->filtros(),
            'orden' => $request->orden(),
            'direccion' => $request->direccion(),
            'porPagina' => $request->porPagina(),
            'parametros' => $request->parametros(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function datosFormulario(Request $request, ?Articulo $articulo = null): array
    {
        $claveProdServ = old('clave_prod_serv', $articulo?->clave_prod_serv);
        $claveUnidad = old('clave_unidad', $articulo?->clave_unidad);

        $catalogos = $request->user()->catalogos()->disponibles()->get();

        return [
            'articulo' => $articulo,
            'catalogos' => $catalogos->pluck('etiqueta', 'id')->all(),
            'descuentos' => $catalogos->pluck('descuento', 'id')->all(),
            'objetosImpuesto' => ObjetoImpuesto::opciones(),
            'descripcionProdServ' => $claveProdServ ? SatClaveProdServ::find($claveProdServ)?->descripcion : null,
            'descripcionUnidad' => $claveUnidad ? SatClaveUnidad::find(mb_strtoupper($claveUnidad))?->nombre : null,
        ];
    }
}
