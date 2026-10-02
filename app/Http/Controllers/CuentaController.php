<?php

namespace App\Http\Controllers;

use App\Enums\TipoCuenta;
use App\Http\Requests\CuentaRequest;
use App\Models\Cuenta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CuentaController extends Controller
{
    /**
     * Listado paginado de las cuentas del usuario, con búsqueda por nombre y
     * filtro por estado.
     */
    public function index(Request $request): View
    {
        $buscar = $request->string('buscar')->trim()->toString();
        $activa = in_array($request->query('activa'), ['1', '0'], true) ? $request->query('activa') : '';

        $cuentas = $request->user()->cuentas()
            ->when($buscar !== '', fn (Builder $consulta) => $consulta->where('nombre', 'like', '%'.addcslashes($buscar, '%_\\').'%'))
            ->when($activa !== '', fn (Builder $consulta) => $consulta->where('activa', $activa === '1'))
            ->withCount('movimientos')
            ->orderBy('nombre')
            ->paginate(25)
            ->withQueryString();

        return view('tesoreria.cuentas.index', [
            'cuentas' => $cuentas,
            'buscar' => $buscar,
            'activa' => $activa,
        ]);
    }

    public function create(): View
    {
        return view('tesoreria.cuentas.crear', ['tipos' => TipoCuenta::opciones()]);
    }

    /**
     * El saldo actual arranca igual al inicial.
     */
    public function store(CuentaRequest $request): RedirectResponse
    {
        $cuenta = new Cuenta($request->validated());
        $cuenta->user_id = $request->user()->id;
        $cuenta->saldo_actual = $cuenta->saldo_inicial;
        $cuenta->save();

        return redirect()->route('tesoreria.cuentas.index')->with('exito', "Cuenta {$cuenta->nombre} creada.");
    }

    public function edit(Cuenta $cuenta): View
    {
        Gate::authorize('update', $cuenta);

        return view('tesoreria.cuentas.editar', ['cuenta' => $cuenta, 'tipos' => TipoCuenta::opciones()]);
    }

    /**
     * CuentaRequest ya verificó que la cuenta es del usuario y no deja pasar
     * el saldo inicial.
     */
    public function update(CuentaRequest $request, Cuenta $cuenta): RedirectResponse
    {
        $cuenta->update($request->safe()->only(['nombre', 'tipo', 'activa']));

        return redirect()->route('tesoreria.cuentas.index')->with('exito', "Cuenta {$cuenta->nombre} actualizada.");
    }

    public function alternarActiva(Cuenta $cuenta): RedirectResponse
    {
        Gate::authorize('update', $cuenta);

        $cuenta->update(['activa' => ! $cuenta->activa]);

        return back()->with('exito', "Cuenta {$cuenta->nombre} ".($cuenta->activa ? 'activada.' : 'desactivada.'));
    }

    /**
     * Borrado físico, solo si la cuenta nunca tuvo movimientos. Si los tiene,
     * el listado ofrece desactivarla.
     */
    public function destroy(Cuenta $cuenta): RedirectResponse
    {
        Gate::authorize('delete', $cuenta);

        if ($cuenta->tieneMovimientos()) {
            return redirect()->route('tesoreria.cuentas.index')
                ->with('error', 'No se puede eliminar: la cuenta tiene movimientos registrados')
                ->with('cuenta_con_movimientos', $cuenta->id);
        }

        $cuenta->delete();

        return redirect()->route('tesoreria.cuentas.index')->with('exito', "Cuenta {$cuenta->nombre} eliminada.");
    }
}
