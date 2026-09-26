<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogoRequest;
use App\Models\Catalogo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CatalogoController extends Controller
{
    /**
     * Listado paginado de los catálogos del usuario, con búsqueda por nombre
     * del catálogo o del proveedor.
     */
    public function index(Request $request): View
    {
        $buscar = $request->string('buscar')->trim()->toString();

        $catalogos = $request->user()->catalogos()
            ->select('catalogos.*')
            ->join('proveedores', 'proveedores.id', '=', 'catalogos.proveedor_id')
            ->when($buscar !== '', fn (Builder $consulta) => $consulta->where(
                fn (Builder $condicion) => $condicion
                    ->where('catalogos.nombre', 'like', "%{$buscar}%")
                    ->orWhere('proveedores.nombre_comercial', 'like', "%{$buscar}%")
            ))
            ->with('proveedor')
            ->withCount('articulos')
            ->orderBy('proveedores.nombre_comercial')
            ->orderBy('catalogos.nombre')
            ->paginate(25)
            ->withQueryString();

        return view('catalogos.index', [
            'catalogos' => $catalogos,
            'buscar' => $buscar,
        ]);
    }

    public function create(Request $request): View
    {
        return view('catalogos.crear', [
            'proveedores' => $request->user()->proveedores()->orderBy('nombre_comercial')->pluck('nombre_comercial', 'id')->all(),
        ]);
    }

    public function store(CatalogoRequest $request): RedirectResponse
    {
        $request->user()->catalogos()->create($request->validated());

        return redirect()->route('catalogos.index')->with('exito', 'Catálogo creado.');
    }

    public function edit(Catalogo $catalogo): View
    {
        Gate::authorize('update', $catalogo);

        return view('catalogos.editar', ['catalogo' => $catalogo]);
    }

    /**
     * CatalogoRequest ya verificó que el catálogo es del usuario. Si cambia el
     * descuento, el modelo recalcula el precio de sus artículos.
     */
    public function update(CatalogoRequest $request, Catalogo $catalogo): RedirectResponse
    {
        $catalogo->update($request->validated());

        return redirect()->route('catalogos.index')->with('exito', 'Catálogo actualizado.');
    }

    /**
     * Borrado lógico, salvo que el catálogo tenga artículos.
     */
    public function destroy(Catalogo $catalogo): RedirectResponse
    {
        Gate::authorize('delete', $catalogo);

        if ($catalogo->articulos()->exists()) {
            return redirect()->route('catalogos.index')
                ->with('error', 'No se puede eliminar: el catálogo tiene artículos asociados');
        }

        $catalogo->delete();

        return redirect()->route('catalogos.index')->with('exito', 'Catálogo eliminado.');
    }
}
