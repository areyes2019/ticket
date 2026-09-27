<?php

namespace App\Http\Controllers;

use App\Enums\EstadoCotizacion;
use App\Enums\FormaPago;
use App\Enums\TasaIva;
use App\Enums\TipoDescuento;
use App\Http\Requests\CotizacionRequest;
use App\Http\Requests\ListadoCotizacionesRequest;
use App\Models\Articulo;
use App\Models\Cotizacion;
use App\Models\CotizacionLinea;
use App\Models\User;
use App\Services\Cotizaciones\GeneradorPdfCotizacion;
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
     * Página completa del listado, con filtros y página de la URL.
     */
    public function index(ListadoCotizacionesRequest $request): View
    {
        return view('cotizaciones.index', $this->datosListado($request, $this->cotizaciones($request)));
    }

    /**
     * Solo filas y paginación, para la búsqueda dinámica (AJAX). Los enlaces
     * de página apuntan al listado completo.
     */
    public function buscar(ListadoCotizacionesRequest $request): View
    {
        $cotizaciones = $this->cotizaciones($request)->withPath(route('cotizaciones.index'));

        return view('cotizaciones._resultados', $this->datosListado($request, $cotizaciones));
    }

    public function create(Request $request): View
    {
        return view('cotizaciones.crear', $this->datosFormulario($request));
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

        return redirect()->route('cotizaciones.show', $cotizacion)
            ->with('exito', "Cotización {$cotizacion->folio_formateado} creada.");
    }

    public function show(Cotizacion $cotizacion): View
    {
        Gate::authorize('view', $cotizacion);

        $cotizacion->load(['cliente', 'lineas', 'pagos']);

        return view('cotizaciones.show', [
            'cotizacion' => $cotizacion,
            'formasPago' => FormaPago::opciones(),
            'hoy' => now(config('app.zona_negocio'))->toDateString(),
        ]);
    }

    public function edit(Request $request, Cotizacion $cotizacion): View
    {
        Gate::authorize('update', $cotizacion);

        return view('cotizaciones.editar', $this->datosFormulario($request, $cotizacion));
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

    public function entregar(Cotizacion $cotizacion): RedirectResponse
    {
        Gate::authorize('operar', $cotizacion);

        if (! $cotizacion->puedeEntregarse()) {
            return back()->with('error', 'Solo una cotización pagada se puede marcar como entregada.');
        }

        $cotizacion->estado = EstadoCotizacion::ProductoEntregado;
        $cotizacion->save();

        return back()->with('exito', 'Cotización marcada como entregada.');
    }

    /**
     * Copia en borrador con folio nuevo, mismo cliente, descuento global y
     * líneas (con su costo original), sin pagos.
     */
    public function duplicar(Request $request, Cotizacion $cotizacion): RedirectResponse
    {
        Gate::authorize('operar', $cotizacion);

        $copia = DB::transaction(function () use ($request, $cotizacion) {
            $copia = $cotizacion->replicate(['folio', 'estado', 'created_at', 'updated_at']);
            $copia->folio = $this->siguienteFolio($request->user());
            $copia->estado = EstadoCotizacion::Borrador;
            $copia->save();

            $copia->lineas()->createMany($cotizacion->lineas->map(
                fn (CotizacionLinea $linea) => $linea->replicate(['cotizacion_id', 'created_at', 'updated_at'])->getAttributes()
            )->all());

            return $copia;
        });

        return redirect()->route('cotizaciones.show', $copia)
            ->with('exito', "Se creó {$copia->folio_formateado} como copia de {$cotizacion->folio_formateado}.");
    }

    /**
     * Consecutivo por usuario que nunca se reutiliza. El bloqueo de la fila del
     * usuario evita que dos altas simultáneas tomen el mismo folio. El máximo
     * existente es una red de seguridad por si el contador se quedó atrás.
     */
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
            'campos' => $request->campos(),
            'periodo' => $request->periodo(),
            'fechaDesde' => $request->fechaDesde()?->toDateString(),
            'fechaHasta' => $request->fechaHasta()?->toDateString(),
            'parametros' => $request->parametros(),
            'hayFiltros' => array_filter($request->campos()) !== [],
        ];
    }

    /**
     * Líneas a pintar: las del intento fallido (old), las guardadas, o
     * ninguna en el alta.
     *
     * @return array<string, mixed>
     */
    private function datosFormulario(Request $request, ?Cotizacion $cotizacion = null): array
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
