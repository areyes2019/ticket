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
     * CatalogoRequest ya verificó que el catálogo es del usuario. Si el cambio
     * mueve el precio de venta o el precio distribuidor de algún artículo,
     * primero se pide confirmación: se vuelve al formulario con lo capturado y
     * el conteo, sin guardar. Al confirmar, el modelo recalcula los artículos.
     */
    public function update(CatalogoRequest $request, Catalogo $catalogo): RedirectResponse
    {
        $datos = $request->validated();
        $afectados = $catalogo->articulosAfectados($datos['descuento'], $datos['utilidad_porcentaje'], $datos['utilidad_distribuidor_porcentaje']);

        if ($afectados > 0 && ! $request->boolean('confirmar')) {
            return redirect()->route('catalogos.edit', $catalogo)
                ->withInput()
                ->with('confirmar_recalculo', $afectados);
        }

        $catalogo->update($datos);

        $mensaje = 'Catálogo actualizado.';

        if ($afectados > 0) {
            $mensaje .= $afectados === 1 ? ' Se recalculó el precio de 1 artículo.' : " Se recalculó el precio de {$afectados} artículos.";
        }

        return redirect()->route('catalogos.index')->with('exito', $mensaje);
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
