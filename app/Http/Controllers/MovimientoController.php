<?php

namespace App\Http\Controllers;

use App\Enums\TipoMovimiento;
use App\Exceptions\CuentaInactivaException;
use App\Exceptions\OperacionTesoreriaRechazada;
use App\Http\Requests\ListadoMovimientosRequest;
use App\Http\Requests\MovimientoRequest;
use App\Models\CotizacionPago;
use App\Models\Cuenta;
use App\Models\Movimiento;
use App\Models\OrdenCompra;
use App\Services\Tesoreria\RegistradorMovimientos;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MovimientoController extends Controller
{
    public function __construct(private readonly RegistradorMovimientos $registrador) {}

    /**
     * Listado con filtros combinables. El documento origen se precarga con
     * sus líneas para calcular la utilidad sin una consulta por fila.
     */
    public function index(ListadoMovimientosRequest $request): View
    {
        $filtros = $request->filtros();
        $usuario = $request->user();

        $movimientos = $usuario->movimientos()
            ->filtrar($filtros)
            ->with([
                'cuenta',
                'documentable' => fn (MorphTo $morph) => $morph->morphWith([CotizacionPago::class => ['cotizacion.lineas'], OrdenCompra::class => []]),
            ])
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(ListadoMovimientosRequest::POR_PAGINA)
            ->withQueryString();

        $cuentas = $usuario->cuentas()->orderBy('nombre')->get();

        return view('tesoreria.movimientos.index', [
            'movimientos' => $movimientos,
            'filtros' => $filtros,
            'contrapartes' => $this->contrapartes($movimientos->getCollection()),
            'cuentas' => $cuentas->pluck('nombre', 'id')->all(),
            'cuentasActivas' => $cuentas->where('activa', true)->pluck('nombre', 'id')->all(),
            'tipos' => TipoMovimiento::opciones(),
            'hoy' => now(config('app.zona_negocio'))->toDateString(),
        ]);
    }

    public function store(MovimientoRequest $request): RedirectResponse
    {
        $tipo = $request->tipo();
        $cuenta = $request->user()->cuentas()->findOrFail($request->validated('cuenta_id'));

        try {
            $movimiento = $this->registrador->registrar(
                $cuenta,
                $tipo,
                $request->validated('monto'),
                $request->validated('fecha'),
                $request->validated('concepto'),
            );
        } catch (OperacionTesoreriaRechazada $rechazo) {
            throw $this->errorDeValidacion($rechazo, $tipo->value);
        }

        return back()->with('exito', "{$tipo->etiqueta()} de {$this->pesos($movimiento->monto)} registrado en {$cuenta->nombre}.");
    }

    /**
     * En su propia página: así no hace falta JavaScript para precargar la
     * fila. La cuenta actual se ofrece aunque ya esté inactiva.
     */
    public function edit(Request $request, Movimiento $movimiento): View
    {
        Gate::authorize('update', $movimiento);

        $cuentas = $request->user()->cuentas()
            ->where(fn ($consulta) => $consulta->where('activa', true)->orWhereKey($movimiento->cuenta_id))
            ->orderBy('nombre')
            ->pluck('nombre', 'id')
            ->all();

        return view('tesoreria.movimientos.editar', ['movimiento' => $movimiento, 'cuentas' => $cuentas]);
    }

    public function update(MovimientoRequest $request, Movimiento $movimiento): RedirectResponse
    {
        $cuenta = $request->user()->cuentas()->findOrFail($request->validated('cuenta_id'));

        try {
            $this->registrador->actualizar(
                $movimiento,
                $cuenta,
                $request->validated('monto'),
                $request->validated('fecha'),
                $request->validated('concepto'),
            );
        } catch (OperacionTesoreriaRechazada $rechazo) {
            throw $this->errorDeValidacion($rechazo, 'default');
        }

        return redirect()->route('tesoreria.movimientos.index')->with('exito', 'Movimiento actualizado.');
    }

    /**
     * En una transferencia se eliminan sus dos movimientos.
     */
    public function destroy(Movimiento $movimiento): RedirectResponse
    {
        Gate::authorize('delete', $movimiento);

        try {
            $this->registrador->eliminar($movimiento);
        } catch (OperacionTesoreriaRechazada $rechazo) {
            return back()->with('error', 'No se puede eliminar: '.lcfirst($rechazo->getMessage()));
        }

        return back()->with('exito', $movimiento->esTransferencia() ? 'Transferencia eliminada.' : 'Movimiento eliminado.');
    }

    /**
     * La cuenta del otro lado de cada transferencia de la página, en una sola
     * consulta: [id del movimiento => cuenta].
     *
     * @param  iterable<Movimiento>  $movimientos
     * @return array<int, Cuenta>
     */
    private function contrapartes(iterable $movimientos): array
    {
        $transferencias = collect($movimientos)->filter(fn (Movimiento $movimiento) => $movimiento->esTransferencia());

        if ($transferencias->isEmpty()) {
            return [];
        }

        $filas = Movimiento::query()
            ->whereIn('transferencia_id', $transferencias->pluck('transferencia_id')->unique())
            ->with('cuenta')
            ->get()
            ->groupBy('transferencia_id');

        return $transferencias->mapWithKeys(fn (Movimiento $movimiento) => [
            $movimiento->id => $filas[$movimiento->transferencia_id]->firstWhere('id', '!=', $movimiento->id)?->cuenta,
        ])->filter()->all();
    }

    private function errorDeValidacion(OperacionTesoreriaRechazada $rechazo, string $bolsa): ValidationException
    {
        $campo = $rechazo instanceof CuentaInactivaException ? 'cuenta_id' : 'monto';

        return ValidationException::withMessages([$campo => $rechazo->getMessage()])->errorBag($bolsa);
    }

    private function pesos(string $monto): string
    {
        return (str_starts_with($monto, '-') ? '-' : '').'$'.number_format(abs((float) $monto), 2);
    }
}
