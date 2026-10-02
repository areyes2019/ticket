<?php

namespace App\Http\Controllers;

use App\Enums\EstadoCotizacion;
use App\Enums\MotivoMovimientoInventario;
use App\Enums\TasaIva;
use App\Enums\TipoDescuento;
use App\Http\Requests\CotizacionRequest;
use App\Http\Requests\DuplicarCotizacionRequest;
use App\Http\Requests\ListadoCotizacionesRequest;
use App\Models\Articulo;
use App\Models\Cotizacion;
use App\Models\CotizacionLinea;
use App\Models\User;
use App\Services\Cotizaciones\GeneradorPdfCotizacion;
use App\Services\Inventario\RegistradorInventario;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CotizacionController extends Controller
{
    /**
     * Bandeja: carpetas (periodos), lista y la vista previa de la cotización
     * pedida en la URL o, si no hay, de la primera de la lista.
     */
    public function index(ListadoCotizacionesRequest $request): View
    {
        $cotizaciones = $this->cotizaciones($request);

        $abierta = $request->abierta() === null ? null : $request->user()->cotizaciones()->find($request->abierta());
        $abierta ??= $cotizaciones->first();
        $abierta?->load(['cliente', 'lineas', 'pagos', 'facturaVigente', 'venta.facturaVigente']);

        return view('cotizaciones.index', [
            ...$this->datosListado($request, $cotizaciones),
            ...($abierta ? self::datosAcciones($abierta) : []),
            'abierta' => $abierta,
        ]);
    }

    /**
     * Solo carpetas, filas y paginación, para la búsqueda dinámica (AJAX). Los
     * enlaces de página apuntan a la bandeja completa.
     */
    public function buscar(ListadoCotizacionesRequest $request): View
    {
        $cotizaciones = $this->cotizaciones($request)->withPath(route('cotizaciones.index'));

        return view('cotizaciones._resultados', $this->datosListado($request, $cotizaciones));
    }

    /**
     * La hoja de la cotización en HTML con sus acciones, para el visor de la
     * bandeja (AJAX).
     */
    public function vistaPrevia(Cotizacion $cotizacion): View
    {
        Gate::authorize('view', $cotizacion);

        return view('cotizaciones._vista-previa', [
            ...self::datosAcciones($cotizacion),
            'cotizacion' => $cotizacion->load(['cliente', 'lineas', 'pagos', 'facturaVigente', 'venta.facturaVigente']),
        ]);
    }

    public function create(Request $request): View
    {
        return view('cotizaciones.crear', self::datosFormulario($request));
    }

    /**
     * El folio, la cotización y sus líneas se guardan juntos.
     */
    public function store(CotizacionRequest $request): RedirectResponse
    {
        $cotizacion = DB::transaction(function () use ($request) {
            $cotizacion = $request->user()->cotizaciones()->make($request->datosCotizacion());
            $cotizacion->folio = $this->siguienteFolio($request->user());
            $cotizacion->aplicarTotales($request->totales());
            $cotizacion->save();

            $this->guardarLineas($cotizacion, $request->lineas(), $request->totales());

            return $cotizacion;
        });

        // Desde la ventana del dashboard, la nueva queda abierta en su visor.
        $destino = $request->input('origen') === 'dashboard'
            ? route('dashboard', ['cotizacion' => $cotizacion->id])
            : route('cotizaciones.show', $cotizacion);

        return redirect()->to($destino)
            ->with('exito', "Cotización {$cotizacion->folio_formateado} creada.");
    }

    public function show(Cotizacion $cotizacion): View
    {
        Gate::authorize('view', $cotizacion);

        $cotizacion->load(['cliente', 'lineas.articulo', 'pagos.cuenta', 'facturaVigente', 'duplicadaDe', 'venta.facturaVigente']);

        return view('cotizaciones.show', [
            ...self::datosAcciones($cotizacion),
            'cotizacion' => $cotizacion,
        ]);
    }

    public function edit(Request $request, Cotizacion $cotizacion): View
    {
        Gate::authorize('update', $cotizacion);

        return view('cotizaciones.editar', self::datosFormulario($request, $cotizacion));
    }

    /**
     * CotizacionRequest ya verificó que es del usuario y que es editable.
     * Editar una enviada la regresa a borrador: hay que reenviarla.
     */
    public function update(CotizacionRequest $request, Cotizacion $cotizacion): RedirectResponse
    {
        $estabaEnviada = $cotizacion->estado === EstadoCotizacion::Enviada;

        DB::transaction(function () use ($request, $cotizacion) {
            $cotizacion->fill($request->datosCotizacion());
            $cotizacion->aplicarTotales($request->totales());
            $cotizacion->estado = EstadoCotizacion::Borrador;
            // Aunque nada haya cambiado, guardar cuenta como movimiento (caducidad).
            $cotizacion->updateTimestamps();
            $cotizacion->save();

            $this->guardarLineas($cotizacion, $request->lineas(), $request->totales());
        });

        $mensaje = "Cotización {$cotizacion->folio_formateado} actualizada.";

        if ($estabaEnviada) {
            $mensaje .= ' Volvió a borrador: reenvíala para que el cliente vea los cambios.';
        }

        return redirect()->route('cotizaciones.show', $cotizacion)->with('exito', $mensaje);
    }

    /**
     * Borrado físico: se lleva las líneas (FK en cascada).
     */
    public function destroy(Cotizacion $cotizacion): RedirectResponse
    {
        $respuesta = Gate::inspect('delete', $cotizacion);

        if ($respuesta->status() === 404) {
            abort(404);
        }

        if ($respuesta->denied()) {
            return back()->with('error', $respuesta->message());
        }

        $cotizacion->delete();

        return redirect()->route('cotizaciones.index')
            ->with('exito', "Cotización {$cotizacion->folio_formateado} eliminada.");
    }

    /**
     * PDF al vuelo: en el navegador, o como descarga con ?descargar=1.
     */
    public function pdf(Request $request, Cotizacion $cotizacion, GeneradorPdfCotizacion $generador): Response
    {
        Gate::authorize('operar', $cotizacion);

        $pdf = $generador->generar($cotizacion);
        $nombre = $generador->nombreArchivo($cotizacion);

        return $request->boolean('descargar') ? $pdf->download($nombre) : $pdf->stream($nombre);
    }

    /**
     * La mercancía sale de existencias al entregarse (018). Es la única salida
     * que da de alta un artículo sin fila: lo crea en 0 y deja el faltante.
     * La comprobación va dentro de la transacción para no descontar dos veces.
     */
    public function entregar(Cotizacion $cotizacion, RegistradorInventario $inventario): RedirectResponse
    {
        Gate::authorize('operar', $cotizacion);

        $entregada = DB::transaction(function () use ($cotizacion, $inventario) {
            $bloqueada = Cotizacion::whereKey($cotizacion->id)->lockForUpdate()->firstOrFail();

            if (! $bloqueada->puedeEntregarse()) {
                return false;
            }

            $bloqueada->estado = EstadoCotizacion::ProductoEntregado;
            $bloqueada->save();

            $inventario->salidaPorDocumento($bloqueada, $bloqueada->lineas, MotivoMovimientoInventario::VentaCotizacion, creaFila: true);

            return true;
        });

        if (! $entregada) {
            return back()->with('error', 'Solo una cotización pagada se puede marcar como entregada.');
        }

        return back()->with('exito', 'Cotización marcada como entregada.');
    }

    /**
     * Copia en borrador para el cliente elegido, con folio nuevo, descuento
     * global y líneas (con su costo original), sin pagos ni factura. Se puede
     * duplicar en cualquier estado.
     */
    public function duplicar(DuplicarCotizacionRequest $request, Cotizacion $cotizacion): RedirectResponse
    {
        $copia = DB::transaction(function () use ($request, $cotizacion) {
            $copia = $cotizacion->replicate(['folio', 'estado', 'aceptada_en', 'duplicada_de_id', 'created_at', 'updated_at']);
            $copia->folio = $this->siguienteFolio($request->user());
            $copia->estado = EstadoCotizacion::Borrador;
            $copia->cliente_id = $request->integer('cliente_id');
            $copia->duplicada_de_id = $cotizacion->id;
            $copia->save();

            $copia->lineas()->createMany($cotizacion->lineas->map(
                fn (CotizacionLinea $linea) => $linea->replicate(['cotizacion_id', 'created_at', 'updated_at'])->getAttributes()
            )->all());

            return $copia;
        });

        // Desde la bandeja, la copia queda abierta en ella.
        $destino = $request->input('origen') === 'bandeja'
            ? route('cotizaciones.index', ['cotizacion' => $copia->id])
            : route('cotizaciones.show', $copia);

        return redirect()->to($destino)
            ->with('exito', "Se creó {$copia->folio_formateado} como copia de {$cotizacion->folio_formateado} para {$copia->cliente->razon_social}.");
    }

    /**
     * Consecutivo por usuario que nunca se reutiliza. El bloqueo de la fila del
     * usuario evita que dos altas simultáneas tomen el mismo folio. El máximo
     * existente es una red de seguridad por si el contador se quedó atrás.
     */
    /**
     * Lo que piden las ventanas de pago y de duplicar, en el detalle y en la
     * vista previa de la bandeja y del dashboard.
     *
     * @return array<string, mixed>
     */
    public static function datosAcciones(Cotizacion $cotizacion): array
    {
        return [
            'cuentas' => $cotizacion->user->cuentas()->activas()->orderBy('nombre')->pluck('nombre', 'id')->all(),
            'hoy' => now(config('app.zona_negocio'))->toDateString(),
            'clientes' => $cotizacion->user->clientes()->orderBy('razon_social')->get()
                ->mapWithKeys(fn ($cliente) => [$cliente->id => $cliente->razon_social.' — '.$cliente->rfc])->all(),
        ];
    }

    private function siguienteFolio(User $user): int
    {
        $bloqueado = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        $folio = max($bloqueado->ultimo_folio_cotizacion, (int) $bloqueado->cotizaciones()->max('folio')) + 1;

        $bloqueado->forceFill(['ultimo_folio_cotizacion' => $folio])->save();

        return $folio;
    }

    /**
     * Reemplaza las líneas. El costo se copia del artículo en este momento
     * (una sola consulta); importe e IVA salen de la calculadora.
     *
     * @param  list<array<string, mixed>>  $lineas
     * @param  array<string, mixed>  $totales
     */
    private function guardarLineas(Cotizacion $cotizacion, array $lineas, array $totales): void
    {
        $costos = Articulo::withTrashed()
            ->whereIn('id', array_filter(array_column($lineas, 'articulo_id')))
            ->pluck('costo_con_descuento', 'id');

        $cotizacion->lineas()->delete();

        foreach ($lineas as $i => $linea) {
            $cotizacion->lineas()->create([
                ...$linea,
                'orden' => $i + 1,
                'importe' => $totales['lineas'][$i]['importe'],
                'iva_importe' => $totales['lineas'][$i]['iva_importe'],
                'costo_unitario' => $linea['articulo_id'] === null ? null : $costos->get($linea['articulo_id']),
            ]);
        }
    }

    /**
     * @return LengthAwarePaginator<int, Cotizacion>
     */
    private function cotizaciones(ListadoCotizacionesRequest $request): LengthAwarePaginator
    {
        return $request->user()->cotizaciones()
            ->with('cliente')
            ->withCount('pagos')
            ->withSum('pagos', 'monto')
            ->withExists('facturaVigente')
            ->filtrar($request->filtros())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(ListadoCotizacionesRequest::POR_PAGINA)
            ->withQueryString();
    }

    /**
     * @param  LengthAwarePaginator<int, Cotizacion>  $cotizaciones
     * @return array<string, mixed>
     */
    private function datosListado(ListadoCotizacionesRequest $request, LengthAwarePaginator $cotizaciones): array
    {
        return [
            'cotizaciones' => $cotizaciones,
            'periodo' => $request->periodo(),
            'estado' => $request->estado(),
            'texto' => $request->texto(),
            'parametros' => $request->parametros(),
            'contadores' => $this->contadores($request->user()),
        ];
    }

    /**
     * Cuántas cotizaciones tiene cada carpeta, sin etiqueta ni búsqueda, en
     * una sola consulta.
     *
     * @return array<string, int>
     */
    private function contadores(User $user): array
    {
        $columnas = [];
        $valores = [];

        foreach (array_keys(ListadoCotizacionesRequest::PERIODOS) as $periodo) {
            [$desde, $hasta] = ListadoCotizacionesRequest::rango($periodo);

            if ($desde === null) {
                $columnas[] = "count(*) as {$periodo}";

                continue;
            }

            $columnas[] = "coalesce(sum(case when created_at between ? and ? then 1 else 0 end), 0) as {$periodo}";
            array_push($valores, $desde->utc(), $hasta->utc());
        }

        $fila = $user->cotizaciones()->toBase()->selectRaw(implode(', ', $columnas), $valores)->first();

        return array_map('intval', (array) $fila);
    }

    /**
     * Líneas a pintar: las del intento fallido (old), las guardadas, o
     * ninguna en el alta. También lo usa la ventana "Nueva cotización" del
     * dashboard.
     *
     * @return array<string, mixed>
     */
    public static function datosFormulario(Request $request, ?Cotizacion $cotizacion = null): array
    {
        $lineas = $request->old('lineas');

        if (! is_array($lineas)) {
            $lineas = $cotizacion?->lineas->map(fn (CotizacionLinea $linea) => [
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
            'cotizacion' => $cotizacion,
            'lineas' => array_values(array_filter($lineas, 'is_array')),
            'clientes' => $request->user()->clientes()->orderBy('razon_social')->get()
                ->mapWithKeys(fn ($cliente) => [$cliente->id => $cliente->razon_social.' — '.$cliente->rfc])->all(),
            'tiposDescuento' => TipoDescuento::opciones(),
            'tasasIva' => TasaIva::opciones(),
        ];
    }
}
