<?php

namespace App\Http\Controllers;

use App\Enums\ClaveConfiguracion;
use App\Enums\EstadoPedido;
use App\Enums\MotivoMovimientoInventario;
use App\Enums\TasaIva;
use App\Enums\TipoDescuento;
use App\Http\Requests\ListadoCotizacionesRequest;
use App\Http\Requests\ListadoPedidosRequest;
use App\Http\Requests\PedidoRequest;
use App\Models\Articulo;
use App\Models\Cotizacion;
use App\Models\Existencia;
use App\Models\Pedido;
use App\Models\PedidoLinea;
use App\Models\User;
use App\Services\Inventario\RegistradorInventario;
use App\Services\OrdenesTrabajo\ConservadorColores;
use App\Services\Pedidos\GeneradorTicketPedido;
use App\Services\Pedidos\MensajePedido;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PedidoController extends Controller
{
    public function __construct(
        private readonly RegistradorInventario $inventario,
        private readonly ConservadorColores $colores,
    ) {}

    public function index(ListadoPedidosRequest $request): View
    {
        return view('pedidos.index', $this->datosListado($request, $this->pedidos($request)));
    }

    /**
     * Solo filas y paginación, para la búsqueda dinámica (AJAX). Los enlaces
     * de página apuntan al listado completo.
     */
    public function buscar(ListadoPedidosRequest $request): View
    {
        $pedidos = $this->pedidos($request)->withPath(route('pedidos.index'));

        return view('pedidos._resultados', $this->datosListado($request, $pedidos));
    }

    /**
     * Nombre y correo de la venta más reciente con ese teléfono, para
     * sugerirlos en el formulario. {} si no hay.
     */
    public function clientePorTelefono(Request $request): JsonResponse
    {
        $digitos = substr((string) preg_replace('/\D/', '', $request->string('telefono')->toString()), -10);

        if (strlen($digitos) !== 10) {
            return response()->json((object) []);
        }

        $anterior = $request->user()->pedidos()
            ->where('cliente_telefono', '+52'.$digitos)
            ->when($request->integer('excepto') > 0, fn ($consulta) => $consulta->whereKeyNot($request->integer('excepto')))
            ->latest('id')
            ->first(['cliente_nombre', 'cliente_correo']);

        return response()->json($anterior === null ? (object) [] : [
            'nombre' => $anterior->cliente_nombre,
            'correo' => $anterior->cliente_correo,
        ]);
    }

    public function create(Request $request): View
    {
        return view('pedidos.crear', $this->datosFormulario($request));
    }

    /**
     * Folio, pedido, líneas y salida de existencias, juntos. Un artículo sin
     * existencia rechaza el pedido completo (la única venta que se bloquea,
     * 019); vender de más deja faltante, como las demás.
     */
    public function store(PedidoRequest $request): RedirectResponse
    {
        $pedido = DB::transaction(function () use ($request) {
            $this->exigirExistencias($request->lineas());

            $pedido = $request->user()->pedidos()->make($request->datosPedido());
            $pedido->folio = Pedido::siguienteFolio($request->user());
            $pedido->aplicarTotales($request->totales());
            $pedido->save();

            $this->guardarLineas($pedido, $request->lineas(), $request->totales());
            $this->inventario->salidaPorDocumento($pedido, $pedido->lineas()->get(), MotivoMovimientoInventario::VentaPedido, creaFila: false);

            return $pedido;
        });

        return redirect()->route('pedidos.show', $pedido)
            ->with('exito', "Venta {$pedido->folio_formateado} creada. Registra el pago para compartir el ticket.");
    }

    public function show(Pedido $pedido, MensajePedido $mensajes): View
    {
        Gate::authorize('view', $pedido);

        $pedido->load(['lineas', 'pagos.cuenta', 'facturaVigente', 'cotizacion.facturaVigente', 'cotizacion.pagos.cuenta', 'cliente', 'ordenTrabajo']);

        $motivoAutofactura = $pedido->motivoAutofacturaNoDisponible();

        return view('pedidos.show', [
            'pedido' => $pedido,
            'cuentas' => $this->cuentas($pedido->user),
            'hoy' => now(config('app.zona_negocio'))->toDateString(),
            'mensajeTicket' => $mensajes->resolver($pedido, ClaveConfiguracion::MensajeTicket),
            'mensajeListo' => $mensajes->resolver($pedido, ClaveConfiguracion::MensajeListo),
            'facturaTimbrada' => $pedido->facturaTimbrada(),
            'textoAutofactura' => $motivoAutofactura === null
                ? "Para generar tu factura de la venta No. {$pedido->numero_ticket} entra a: {$pedido->urlAutofactura()} . El enlace vence el {$pedido->autofacturaVenceEl()->format('d/m/Y')}."
                : null,
        ]);
    }

    public function edit(Request $request, Pedido $pedido): View
    {
        Gate::authorize('update', $pedido);

        return view('pedidos.editar', $this->datosFormulario($request, $pedido));
    }

    /**
     * PedidoRequest ya verificó que es del usuario y editable. Primero se
     * devuelve lo que el pedido sacó: así uno que se llevó las últimas piezas
     * se puede editar sin que su propio descuento lo bloquee. La venta de una
     * cotización no se bloquea por existencia (021): deja faltante. Los
     * colores de su orden de trabajo pasan a las líneas nuevas (022).
     */
    public function update(PedidoRequest $request, Pedido $pedido): RedirectResponse
    {
        DB::transaction(function () use ($request, $pedido) {
            $bloqueado = Pedido::whereKey($pedido->id)->lockForUpdate()->firstOrFail();

            if (! $bloqueado->esEditable()) {
                throw ValidationException::withMessages(['lineas' => 'La venta ya no se puede editar: '.mb_strtolower($bloqueado->estado->etiqueta()).'.']);
            }

            $this->inventario->revertirDocumento($bloqueado, MotivoMovimientoInventario::CorreccionPedido);

            if (! $bloqueado->esDeCotizacion()) {
                $this->exigirExistencias($request->lineas());
            }

            $bloqueado->fill($request->datosPedido());
            $bloqueado->aplicarTotales($request->totales());
            $colores = $this->colores->recordar($bloqueado);
            $this->guardarLineas($bloqueado, $request->lineas(), $request->totales());
            $this->colores->reaplicar($bloqueado, $colores);
            $bloqueado->recalcularEstado();
            $bloqueado->save();

            $this->inventario->salidaPorDocumento($bloqueado, $bloqueado->lineas()->get(), MotivoMovimientoInventario::VentaPedido, creaFila: $bloqueado->esDeCotizacion());
        });

        return redirect()->route('pedidos.show', $pedido)
            ->with('exito', "Venta {$pedido->folio_formateado} actualizada.");
    }

    /**
     * Borrado físico (se lleva las líneas) y devolución de existencias. Si
     * nació de una cotización, deshace la aceptación: la cotización vuelve a
     * enviada (021). Su orden de trabajo se borra con Eloquent para que se
     * lleve también el archivo del diseño (022).
     */
    public function destroy(Pedido $pedido): RedirectResponse
    {
        $respuesta = Gate::inspect('delete', $pedido);

        if ($respuesta->status() === 404) {
            abort(404);
        }

        if ($respuesta->denied()) {
            return back()->with('error', $respuesta->message());
        }

        $cotizacion = DB::transaction(function () use ($pedido) {
            $this->inventario->revertirDocumento($pedido, MotivoMovimientoInventario::CorreccionPedido);

            $cotizacion = $pedido->esDeCotizacion()
                ? Cotizacion::whereKey($pedido->cotizacion_id)->lockForUpdate()->first()
                : null;

            $pedido->ordenTrabajo?->delete();
            $pedido->delete();
            $cotizacion?->revertirAceptacion();

            return $cotizacion;
        });

        $mensaje = "Venta {$pedido->folio_formateado} eliminada. Sus artículos regresaron a existencias.";

        if ($cotizacion !== null) {
            $mensaje .= " La cotización {$cotizacion->folio_formateado} volvió a Enviada.";
        }

        return redirect()->route('pedidos.index')->with('exito', $mensaje);
    }

    /**
     * Ticket JPG dibujado en el momento: no se guarda en ningún lado.
     */
    public function ticket(Request $request, Pedido $pedido, GeneradorTicketPedido $generador): Response
    {
        Gate::authorize('operar', $pedido);

        $disposicion = $request->boolean('descargar') ? 'attachment' : 'inline';

        return response($generador->generar($pedido), 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => $disposicion.'; filename="'.$generador->nombreArchivo($pedido).'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Vista de impresión de 50 × 25 mm, sin el layout de la aplicación.
     */
    public function etiqueta(Pedido $pedido): View
    {
        Gate::authorize('operar', $pedido);

        return view('pedidos.etiqueta', ['pedido' => $pedido]);
    }

    /**
     * Cada línea con artículo debe tener fila viva en existencias con al menos
     * una pieza. Vender por arriba de lo disponible sí se permite.
     *
     * @param  list<array<string, mixed>>  $lineas
     */
    private function exigirExistencias(array $lineas): void
    {
        $ids = array_values(array_unique(array_filter(array_column($lineas, 'articulo_id'))));
        $filas = Existencia::whereIn('articulo_id', $ids)->get()->keyBy('articulo_id');
        $errores = [];

        foreach ($lineas as $i => $linea) {
            if ($linea['articulo_id'] === null) {
                continue;
            }

            $fila = $filas->get($linea['articulo_id']);
            $nombre = $linea['modelo'] ?? $linea['descripcion'];

            if ($fila === null) {
                $errores["lineas.{$i}.articulo_id"] = "{$nombre} no está en existencias: márcalo en Existencias para poder venderlo.";
            } elseif ($fila->existencia <= 0) {
                $errores["lineas.{$i}.articulo_id"] = "{$nombre} no tiene existencia en bodega.";
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /**
     * Reemplaza las líneas. El costo se copia del artículo en este momento
     * (una sola consulta); importe e IVA salen de la calculadora.
     *
     * @param  list<array<string, mixed>>  $lineas
     * @param  array<string, mixed>  $totales
     */
    private function guardarLineas(Pedido $pedido, array $lineas, array $totales): void
    {
        $costos = Articulo::withTrashed()
            ->whereIn('id', array_filter(array_column($lineas, 'articulo_id')))
            ->pluck('costo_con_descuento', 'id');

        $pedido->lineas()->delete();

        foreach ($lineas as $i => $linea) {
            $pedido->lineas()->create([
                ...$linea,
                'orden' => $i + 1,
                'importe' => $totales['lineas'][$i]['importe'],
                'iva_importe' => $totales['lineas'][$i]['iva_importe'],
                'costo_unitario' => $linea['articulo_id'] === null ? null : $costos->get($linea['articulo_id']),
            ]);
        }
    }

    /**
     * @return array<int|string, string>
     */
    private function cuentas(User $user): array
    {
        return $user->cuentas()->activas()->orderBy('nombre')->pluck('nombre', 'id')->all();
    }

    /**
     * @return LengthAwarePaginator<int, Pedido>
     */
    private function pedidos(ListadoPedidosRequest $request): LengthAwarePaginator
    {
        return $request->user()->pedidos()
            // Las que cobran en la cotización (029) suman los pagos de ahí, sin una consulta por fila.
            ->with(['cotizacion' => fn ($cotizaciones) => $cotizaciones->select('id', 'folio')->withSum('pagos', 'monto')->withCount('pagos'), 'ordenTrabajo:id,pedido_id'])
            ->withSum('pagos', 'monto')
            ->filtrar($request->filtros())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(ListadoPedidosRequest::POR_PAGINA)
            ->withQueryString();
    }

    /**
     * @param  LengthAwarePaginator<int, Pedido>  $pedidos
     * @return array<string, mixed>
     */
    private function datosListado(ListadoPedidosRequest $request, LengthAwarePaginator $pedidos): array
    {
        return [
            'pedidos' => $pedidos,
            'filtros' => $request->valores(),
            'estados' => EstadoPedido::opciones(),
            'origenes' => ListadoPedidosRequest::ORIGENES,
            'periodos' => ListadoCotizacionesRequest::PERIODOS,
        ];
    }

    /**
     * Líneas a pintar: las del intento fallido (old), las guardadas, o
     * ninguna en el alta.
     *
     * @return array<string, mixed>
     */
    private function datosFormulario(Request $request, ?Pedido $pedido = null): array
    {
        $lineas = $request->old('lineas');

        if (! is_array($lineas)) {
            $lineas = $pedido?->lineas->map(fn (PedidoLinea $linea) => [
                'articulo_id' => $linea->articulo_id,
                'cantidad' => $linea->cantidad,
                'descripcion' => $linea->descripcion,
                'modelo' => $linea->modelo,
                'precio_unitario' => $linea->precio_unitario,
                'descuento_tipo' => $linea->descuento_tipo?->value,
                'descuento_valor' => $linea->descuento_valor,
                'tasa_iva' => $linea->tasa_iva->value,
                'importe' => $linea->importe,
            ])->all() ?? [];
        }

        return [
            'pedido' => $pedido,
            'lineas' => array_values(array_filter($lineas, 'is_array')),
            'tiposDescuento' => TipoDescuento::opciones(),
            'tasasIva' => TasaIva::opciones(),
        ];
    }
}
