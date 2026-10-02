<?php

namespace App\Http\Controllers;

use App\Enums\EstadoOrdenCompra;
use App\Enums\TasaIva;
use App\Enums\TipoDescuento;
use App\Http\Controllers\Concerns\RegresaABandeja;
use App\Http\Requests\ListadoOrdenesCompraRequest;
use App\Http\Requests\OrdenCompraRequest;
use App\Models\OrdenCompra;
use App\Models\OrdenCompraLinea;
use App\Models\User;
use App\Services\OrdenesCompra\GeneradorPdfOrdenCompra;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OrdenCompraController extends Controller
{
    use RegresaABandeja;

    /**
     * Bandeja: carpetas (periodos), lista y la vista previa de la orden pedida
     * en la URL o, si no hay, de la primera de la lista.
     */
    public function index(ListadoOrdenesCompraRequest $request): View
    {
        $ordenes = $this->ordenes($request);

        $abierta = $request->abierta() === null ? null : $request->user()->ordenesCompra()->find($request->abierta());
        $abierta ??= $ordenes->first();
        $abierta?->load(['proveedor', 'lineas', 'cuenta']);

        return view('ordenes-compra.index', [
            ...$this->datosListado($request, $ordenes),
            ...($abierta ? $this->datosAcciones($abierta) : []),
            'abierta' => $abierta,
        ]);
    }

    /**
     * Solo carpetas, filas y paginación, para la búsqueda dinámica (AJAX). Los
     * enlaces de página apuntan a la bandeja completa.
     */
    public function buscar(ListadoOrdenesCompraRequest $request): View
    {
        $ordenes = $this->ordenes($request)->withPath(route('ordenes-compra.index'));

        return view('ordenes-compra._resultados', $this->datosListado($request, $ordenes));
    }

    /**
     * La hoja de la orden en HTML con sus acciones, para el visor de la
     * bandeja (AJAX).
     */
    public function vistaPrevia(OrdenCompra $ordenCompra): View
    {
        Gate::authorize('view', $ordenCompra);

        return view('ordenes-compra._vista-previa', [
            ...$this->datosAcciones($ordenCompra),
            'orden' => $ordenCompra->load(['proveedor', 'lineas', 'cuenta']),
        ]);
    }

    public function create(Request $request): View
    {
        return view('ordenes-compra.crear', $this->datosFormulario($request));
    }

    /**
     * El folio, la orden y sus líneas se guardan juntos.
     */
    public function store(OrdenCompraRequest $request): RedirectResponse
    {
        $orden = DB::transaction(function () use ($request) {
            $orden = $request->user()->ordenesCompra()->make($request->datosOrden());
            $orden->folio = $this->siguienteFolio($request->user());
            $orden->aplicarTotales($request->totales());
            $orden->save();

            $this->guardarLineas($orden, $request->lineas(), $request->totales());

            return $orden;
        });

        return redirect()->route('ordenes-compra.show', $orden)
            ->with('exito', "Orden de compra {$orden->folio_formateado} creada.");
    }

    public function show(OrdenCompra $ordenCompra): View
    {
        Gate::authorize('view', $ordenCompra);

        $ordenCompra->load(['proveedor', 'lineas', 'cuenta', 'duplicadaDe']);

        return view('ordenes-compra.show', [
            ...$this->datosAcciones($ordenCompra),
            'orden' => $ordenCompra,
        ]);
    }

    public function edit(Request $request, OrdenCompra $ordenCompra): View
    {
        Gate::authorize('update', $ordenCompra);

        return view('ordenes-compra.editar', $this->datosFormulario($request, $ordenCompra));
    }

    /**
     * OrdenCompraRequest ya verificó que es del usuario y que es editable.
     * Editar una enviada la regresa a borrador: hay que reenviarla.
     */
    public function update(OrdenCompraRequest $request, OrdenCompra $ordenCompra): RedirectResponse
    {
        $estabaEnviada = $ordenCompra->estado === EstadoOrdenCompra::Enviada;

        DB::transaction(function () use ($request, $ordenCompra) {
            $ordenCompra->fill($request->datosOrden());
            $ordenCompra->aplicarTotales($request->totales());
            $ordenCompra->estado = EstadoOrdenCompra::Borrador;
            $ordenCompra->save();

            $this->guardarLineas($ordenCompra, $request->lineas(), $request->totales());
        });

        $mensaje = "Orden de compra {$ordenCompra->folio_formateado} actualizada.";

        if ($estabaEnviada) {
            $mensaje .= ' Volvió a borrador: reenvíala para que el proveedor vea los cambios.';
        }

        return redirect()->route('ordenes-compra.show', $ordenCompra)->with('exito', $mensaje);
    }

    /**
     * Borrado físico: se lleva las líneas (FK en cascada).
     */
    public function destroy(OrdenCompra $ordenCompra): RedirectResponse
    {
        $respuesta = Gate::inspect('delete', $ordenCompra);

        if ($respuesta->status() === 404) {
            abort(404);
        }

        if ($respuesta->denied()) {
            return back()->with('error', $respuesta->message());
        }

        $ordenCompra->delete();

        return redirect()->route('ordenes-compra.index')
            ->with('exito', "Orden de compra {$ordenCompra->folio_formateado} eliminada.");
    }

    /**
     * PDF al vuelo: en el navegador, o como descarga con ?descargar=1.
     */
    public function pdf(Request $request, OrdenCompra $ordenCompra, GeneradorPdfOrdenCompra $generador): Response
    {
        Gate::authorize('operar', $ordenCompra);

        $pdf = $generador->generar($ordenCompra);
        $nombre = $generador->nombreArchivo($ordenCompra);

        return $request->boolean('descargar') ? $pdf->download($nombre) : $pdf->stream($nombre);
    }

    /**
     * Recepción manual, total e irreversible. No toca existencias: el sistema
     * no lleva inventario.
     */
    public function recibir(Request $request, OrdenCompra $ordenCompra): RedirectResponse
    {
        Gate::authorize('operar', $ordenCompra);

        if (! $ordenCompra->puedeRecibirse()) {
            return back()->with('error', 'Solo una orden pagada se puede marcar como recibida.');
        }

        $ordenCompra->estado = EstadoOrdenCompra::Recibida;
        $ordenCompra->save();

        return redirect()->to($this->destinoOrdenCompra($request, $ordenCompra))
            ->with('exito', "Orden de compra {$ordenCompra->folio_formateado} recibida.");
    }

    /**
     * Copia en borrador para el mismo proveedor, con folio nuevo, líneas,
     * descuento global y observaciones; sin pago ni fecha esperada. Se puede
     * duplicar en cualquier estado.
     */
    public function duplicar(Request $request, OrdenCompra $ordenCompra): RedirectResponse
    {
        Gate::authorize('operar', $ordenCompra);

        $copia = DB::transaction(function () use ($request, $ordenCompra) {
            $copia = $ordenCompra->replicate(['folio', 'estado', 'duplicada_de_id', 'fecha_entrega_esperada', 'cuenta_id', 'fecha_pago', 'created_at', 'updated_at']);
            $copia->folio = $this->siguienteFolio($request->user());
            $copia->estado = EstadoOrdenCompra::Borrador;
            $copia->duplicada_de_id = $ordenCompra->id;
            $copia->save();

            $copia->lineas()->createMany($ordenCompra->lineas->map(
                fn (OrdenCompraLinea $linea) => $linea->replicate(['orden_compra_id', 'created_at', 'updated_at'])->getAttributes()
            )->all());

            return $copia;
        });

        // Desde la bandeja, la copia queda abierta en ella.
        $destino = $request->input('origen') === 'bandeja'
            ? route('ordenes-compra.index', ['orden' => $copia->id])
            : route('ordenes-compra.show', $copia);

        return redirect()->to($destino)
            ->with('exito', "Se creó {$copia->folio_formateado} como copia de {$ordenCompra->folio_formateado}.");
    }

    /**
     * Lo que pide la ventana de pago, en el detalle y en la vista previa de
     * la bandeja.
     *
     * @return array<string, mixed>
     */
    private function datosAcciones(OrdenCompra $orden): array
    {
        return [
            'cuentas' => $orden->user->cuentas()->activas()->orderBy('nombre')->pluck('nombre', 'id')->all(),
            'hoy' => now(config('app.zona_negocio'))->toDateString(),
        ];
    }

    /**
     * Consecutivo por usuario que nunca se reutiliza. El bloqueo de la fila del
     * usuario evita que dos altas simultáneas tomen el mismo folio. El máximo
     * existente es una red de seguridad por si el contador se quedó atrás.
     */
    private function siguienteFolio(User $user): int
    {
        $bloqueado = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        $folio = max($bloqueado->ultimo_folio_orden_compra, (int) $bloqueado->ordenesCompra()->max('folio')) + 1;

        $bloqueado->forceFill(['ultimo_folio_orden_compra' => $folio])->save();

        return $folio;
    }

    /**
     * Reemplaza las líneas; importe e IVA salen de la calculadora.
     *
     * @param  list<array<string, mixed>>  $lineas
     * @param  array<string, mixed>  $totales
     */
    private function guardarLineas(OrdenCompra $orden, array $lineas, array $totales): void
    {
        $orden->lineas()->delete();

        foreach ($lineas as $i => $linea) {
            $orden->lineas()->create([
                ...$linea,
                'orden' => $i + 1,
                'importe' => $totales['lineas'][$i]['importe'],
                'iva_importe' => $totales['lineas'][$i]['iva_importe'],
            ]);
        }
    }

    /**
     * @return LengthAwarePaginator<int, OrdenCompra>
     */
    private function ordenes(ListadoOrdenesCompraRequest $request): LengthAwarePaginator
    {
        return $request->user()->ordenesCompra()
            ->with('proveedor')
            ->filtrar($request->filtros())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(ListadoOrdenesCompraRequest::POR_PAGINA)
            ->withQueryString();
    }

    /**
     * @param  LengthAwarePaginator<int, OrdenCompra>  $ordenes
     * @return array<string, mixed>
     */
    private function datosListado(ListadoOrdenesCompraRequest $request, LengthAwarePaginator $ordenes): array
    {
        return [
            'ordenes' => $ordenes,
            'periodo' => $request->periodo(),
            'estado' => $request->estado(),
            'texto' => $request->texto(),
            'parametros' => $request->parametros(),
            'contadores' => $this->contadores($request->user()),
        ];
    }

    /**
     * Cuántas órdenes tiene cada carpeta, sin etiqueta ni búsqueda, en una
     * sola consulta.
     *
     * @return array<string, int>
     */
    private function contadores(User $user): array
    {
        $columnas = [];
        $valores = [];

        foreach (array_keys(ListadoOrdenesCompraRequest::PERIODOS) as $periodo) {
            [$desde, $hasta] = ListadoOrdenesCompraRequest::rango($periodo);

            if ($desde === null) {
                $columnas[] = "count(*) as {$periodo}";

                continue;
            }

            $columnas[] = "coalesce(sum(case when created_at between ? and ? then 1 else 0 end), 0) as {$periodo}";
            array_push($valores, $desde->utc(), $hasta->utc());
        }

        $fila = $user->ordenesCompra()->toBase()->selectRaw(implode(', ', $columnas), $valores)->first();

        return array_map('intval', (array) $fila);
    }

    /**
     * Líneas a pintar: las del intento fallido (old), las guardadas, o
     * ninguna en el alta.
     *
     * @return array<string, mixed>
     */
    private function datosFormulario(Request $request, ?OrdenCompra $orden = null): array
    {
        $lineas = $request->old('lineas');

        if (! is_array($lineas)) {
            $lineas = $orden?->lineas->map(fn (OrdenCompraLinea $linea) => [
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

        $proveedores = $request->user()->proveedores()
            ->when($orden, fn ($consulta) => $consulta->withTrashed()->where(
                fn ($activos) => $activos->whereNull('deleted_at')->orWhere('id', $orden->proveedor_id)
            ))
            ->orderBy('nombre_comercial')
            ->get();

        return [
            'orden' => $orden,
            'lineas' => array_values(array_filter($lineas, 'is_array')),
            'proveedores' => $proveedores->mapWithKeys(fn ($proveedor) => [
                $proveedor->id => $proveedor->nombre_comercial.($proveedor->rfc ? ' — '.$proveedor->rfc : ''),
            ])->all(),
            'tiposDescuento' => TipoDescuento::opciones(),
            'tasasIva' => TasaIva::opciones(),
        ];
    }
}
