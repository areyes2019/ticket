<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RegresaAMostrador;
use App\Http\Requests\ClienteRequest;
use App\Models\Cliente;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ClienteController extends Controller
{
    use RegresaAMostrador;

    /**
     * Página completa del listado, con los filtros de la URL.
     */
    public function index(Request $request): View
    {
        return view('clientes.index', [
            'clientes' => $this->clientesFiltrados($request),
            'filtros' => $this->filtros($request),
        ]);
    }

    /**
     * Solo las filas y la paginación, para la búsqueda dinámica (AJAX).
     * Los enlaces de página apuntan al listado completo para que se puedan
     * abrir sin JavaScript o compartir.
     */
    public function buscar(Request $request): View
    {
        $clientes = $this->clientesFiltrados($request)->withPath(route('clientes.index'));

        return view('clientes._resultados', [
            'clientes' => $clientes,
            'filtros' => $this->filtros($request),
        ]);
    }

    public function create(): View
    {
        return view('clientes.crear');
    }

    /**
     * Desde el mostrador (033) regresa a la captura con el cliente elegido.
     */
    public function store(ClienteRequest $request): RedirectResponse
    {
        $cliente = $request->user()->clientes()->create($request->validated());
        $flujo = $request->input('flujo');

        if ($this->vieneDelMostrador($request) && in_array($flujo, MostradorController::FLUJOS_CON_CLIENTE, true)) {
            return redirect()->route("mostrador.{$flujo}", ['cliente' => $cliente->id])
                ->with('exito', "Cliente {$cliente->razon_social} creado.");
        }

        return redirect()->route('clientes.index')->with('exito', 'Cliente creado.');
    }

    public function edit(Cliente $cliente): View
    {
        Gate::authorize('update', $cliente);

        return view('clientes.editar', ['cliente' => $cliente]);
    }

    /**
     * ClienteRequest ya verificó que el cliente es del usuario.
     */
    public function update(ClienteRequest $request, Cliente $cliente): RedirectResponse
    {
        $cliente->update($request->validated());

        return redirect()->route('clientes.index')->with('exito', 'Cliente actualizado.');
    }

    /**
     * Borrado lógico.
     *
     * Regla de negocio pendiente: no se debe eliminar un cliente con facturas
     * timbradas. Se verificará aquí cuando exista el módulo de facturación.
     */
    public function destroy(Cliente $cliente): RedirectResponse
    {
        Gate::authorize('delete', $cliente);

        $cliente->delete();

        return redirect()->route('clientes.index')->with('exito', 'Cliente eliminado.');
    }

    /**
     * @return LengthAwarePaginator<int, Cliente>
     */
    private function clientesFiltrados(Request $request): LengthAwarePaginator
    {
        return $request->user()->clientes()
            ->filtrar($this->filtros($request))
            ->orderBy('razon_social')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * @return array<string, string>
     */
    private function filtros(Request $request): array
    {
        $filtros = [];

        foreach (Cliente::FILTROS as $filtro) {
            $filtros[$filtro] = $request->string($filtro)->trim()->toString();
        }

        return $filtros;
    }
}
