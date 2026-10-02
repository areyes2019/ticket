<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProveedorRequest;
use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProveedorController extends Controller
{
    /**
     * Listado paginado de los proveedores del usuario, con búsqueda.
     */
    public function index(Request $request): View
    {
        $buscar = $request->string('buscar')->trim()->toString();

        $proveedores = $request->user()->proveedores()
            ->when($buscar !== '', fn (Builder $consulta) => $consulta->where(
                fn (Builder $condicion) => $condicion
                    ->where('nombre_comercial', 'like', "%{$buscar}%")
                    ->orWhere('nombre_contacto', 'like', "%{$buscar}%")
            ))
            ->orderBy('nombre_comercial')
            ->paginate(25)
            ->withQueryString();

        return view('proveedores.index', [
            'proveedores' => $proveedores,
            'buscar' => $buscar,
        ]);
    }

    public function create(): View
    {
        return view('proveedores.crear');
    }

    public function store(ProveedorRequest $request): RedirectResponse
    {
        $request->user()->proveedores()->create($request->validated());

        return redirect()->route('proveedores.index')->with('exito', 'Proveedor creado.');
    }

    public function edit(Proveedor $proveedor): View
    {
        Gate::authorize('update', $proveedor);

        return view('proveedores.editar', ['proveedor' => $proveedor]);
    }

    /**
     * ProveedorRequest ya verificó que el proveedor es del usuario.
     */
    public function update(ProveedorRequest $request, Proveedor $proveedor): RedirectResponse
    {
        $proveedor->update($request->validated());

        return redirect()->route('proveedores.index')->with('exito', 'Proveedor actualizado.');
    }

    /**
     * Borrado lógico, salvo que el proveedor tenga órdenes de compra activas.
     */
    public function destroy(Proveedor $proveedor): RedirectResponse
    {
        Gate::authorize('delete', $proveedor);

        if ($proveedor->tieneOrdenesActivas()) {
            return redirect()->route('proveedores.index')
                ->with('error', 'No se puede eliminar: tiene órdenes de compra activas');
        }

        $proveedor->delete();

        return redirect()->route('proveedores.index')->with('exito', 'Proveedor eliminado.');
    }
}
