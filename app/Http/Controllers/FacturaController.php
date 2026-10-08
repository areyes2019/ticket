<?php

namespace App\Http\Controllers;

use App\Enums\EstadoCancelacion;
use App\Enums\EstadoFactura;
use App\Enums\FormaPago;
use App\Enums\MetodoPago;
use App\Enums\MotivoCancelacion;
use App\Enums\TasaIva;
use App\Enums\TipoDescuento;
use App\Enums\UsoCfdi;
use App\Http\Controllers\Concerns\RegresaAMostrador;
use App\Http\Requests\CancelarFacturaRequest;
use App\Http\Requests\FacturaRequest;
use App\Http\Requests\ListadoCotizacionesRequest;
use App\Http\Requests\ListadoFacturasRequest;
use App\Http\Requests\TimbrarCotizacionRequest;
use App\Models\Articulo;
use App\Models\Cotizacion;
use App\Models\CotizacionLinea;
use App\Models\Factura;
use App\Models\FacturaLinea;
use App\Models\User;
use App\Services\Facturacion\CanceladorFacturas;
use App\Services\Facturacion\FacturapiCliente;
use App\Services\Facturacion\FacturapiException;
use App\Services\Facturacion\GeneradorPdfFactura;
use App\Services\Facturacion\ResultadoTimbrado;
use App\Services\Facturacion\TimbradorFacturas;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class FacturaController extends Controller
{
    use RegresaAMostrador;

    public function __construct(private TimbradorFacturas $timbrador) {}

    /**
     * Página completa del listado, con filtros y página de la URL.
     */
    public function index(ListadoFacturasRequest $request): View
    {
        return view('facturas.index', $this->datosListado($request, $this->facturas($request)));
    }

    /**
     * Solo filas y paginación, para la búsqueda dinámica (AJAX). Los enlaces
     * de página apuntan al listado completo.
     */
    public function buscar(ListadoFacturasRequest $request): View
    {
        $facturas = $this->facturas($request)->withPath(route('facturas.index'));

        return view('facturas._resultados', $this->datosListado($request, $facturas));
    }

    /**
     * La factura como hoja en HTML con sus acciones, para el visor del
     * dashboard (AJAX). No consulta la cancelación en facturapi.io: muestra el
     * último estado conocido; el detalle lo refresca.
     */
    public function vistaPrevia(Factura $factura): View
    {
        Gate::authorize('view', $factura);

        return view('facturas._vista-previa', [
            'factura' => $factura->load(['cliente', 'lineas']),
        ]);
    }

    /**
     * Formulario de alta: vacío, lleno con una cotización (?cotizacion=) o
     * con una copia de otra factura (?duplicar=&cliente_id=). No guarda nada:
     * la factura nace al pulsar "Generar y timbrar".
     */
    public function create(Request $request): View|RedirectResponse
    {
        if ($request->filled('cotizacion')) {
            $cotizacion = $request->user()->cotizaciones()->findOrFail($request->integer('cotizacion'));
            $motivo = $cotizacion->motivoNoFacturable();

            if ($motivo !== null) {
                return redirect()->route('cotizaciones.show', $cotizacion)->with('error', $motivo);
            }

            return view('facturas.crear', $this->datosFormulario($request, precarga: $this->precargaDeCotizacion($cotizacion)));
        }

        if ($request->filled('duplicar')) {
            $original = $request->user()->facturas()->findOrFail($request->integer('duplicar'));

            return view('facturas.crear', $this->datosFormulario($request, precarga: $this->precargaDeFactura($request, $original)));
        }

        return view('facturas.crear', $this->datosFormulario($request));
    }

    /**
     * Guarda la captura completa (folio, factura y líneas) y, ya confirmada la
     * transacción, intenta timbrar. Si el timbrado falla la factura queda
     * pendiente: la captura no se pierde.
     */
    public function store(FacturaRequest $request): RedirectResponse
    {
        $resultado = $this->guardarNueva(
            $request,
            yaFacturada: fn (Factura $vigente) => redirect()->route('facturas.show', $vigente)
                ->with('error', "Esta cotización ya se facturó en {$vigente->folioVisible()}."),
            noFacturable: fn (string $motivo) => back()->withInput()->with('error', $motivo),
        );

        if ($resultado instanceof RedirectResponse) {
            return $resultado;
        }

        return $this->respuestaTimbrado($resultado, $this->timbrador->timbrar($resultado), $this->vieneDelMostrador($request));
    }

    /**
     * Timbrado directo desde la vista previa de una cotización (spec 020): la
     * factura nace con los datos de la cotización y los fiscales elegidos en
     * la confirmación, y se timbra en ese momento. Con JavaScript responde
     * JSON con las filas nuevas para la página; sin él, igual que store.
     */
    public function timbrarCotizacion(TimbrarCotizacionRequest $request, Cotizacion $cotizacion): JsonResponse|RedirectResponse
    {
        if (! $request->expectsJson()) {
            return $this->store($request);
        }

        $resultado = $this->guardarNueva(
            $request,
            yaFacturada: fn (Factura $vigente) => response()->json([
                'mensaje' => "Esta cotización ya se facturó en {$vigente->folioVisible()}.",
                'url' => route('facturas.show', $vigente),
            ], 409),
            noFacturable: fn (string $motivo) => response()->json(['mensaje' => $motivo], 422),
        );

        if ($resultado instanceof JsonResponse) {
            return $resultado;
        }

        $timbrado = $this->timbrador->timbrar($resultado);
        $factura = $resultado->refresh()->load('cliente');
        $cotizacion = $cotizacion->fresh('cliente')->loadExists('facturaVigente');

        return response()->json([
            'tipo' => $timbrado === ResultadoTimbrado::Timbrada ? 'exito' : 'error',
            'mensaje' => $this->mensajeTimbrado($factura, $timbrado) ?? "Factura {$factura->folioVisible()} creada.",
            'factura' => $factura->id,
            'fila' => Blade::render('<x-facturas.fila :factura="$factura" />', ['factura' => $factura]),
            'filaCotizacion' => Blade::render('<x-cotizaciones.fila :cotizacion="$cotizacion" :activa="true" />', ['cotizacion' => $cotizacion]),
        ]);
    }

    /**
     * Folio, factura y líneas en una transacción. Si sale de una cotización,
     * su fila se bloquea y se vuelve a revisar: dos clics o dos pestañas no
     * crean dos facturas. Si ya tiene factura vigente o no se puede facturar,
     * devuelve la respuesta que arma quien llama.
     *
     * @template TRespuesta of Response|RedirectResponse|JsonResponse
     *
     * @param  Closure(Factura): TRespuesta  $yaFacturada
     * @param  Closure(string): TRespuesta  $noFacturable
     * @return Factura|TRespuesta
     */
    private function guardarNueva(FacturaRequest $request, Closure $yaFacturada, Closure $noFacturable): mixed
    {
        $origen = $request->origen();

        return DB::transaction(function () use ($request, $origen, $yaFacturada, $noFacturable) {
            if ($origen['cotizacion_id'] !== null) {
                $cotizacion = Cotizacion::whereKey($origen['cotizacion_id'])->lockForUpdate()->firstOrFail();
                $vigente = $cotizacion->facturaVigente()->first();

                if ($vigente !== null) {
                    return $yaFacturada($vigente);
                }

                $motivo = $cotizacion->motivoNoFacturable();

                if ($motivo !== null) {
                    return $noFacturable($motivo);
                }
            }

            $factura = $request->user()->facturas()->make($request->datosFactura());
            $factura->forceFill($origen);
            $factura->folio = Factura::siguienteFolio($request->user());
            $factura->aplicarTotales($request->totales());
            $factura->save();

            $this->guardarLineas($factura, $request);

            return $factura;
        });
    }

    /**
     * Cotizaciones que se pueden facturar ya, para la ventana "Desde
     * cotización": página completa sin JavaScript, o solo la lista con
     * ?fragmento=1.
     */
    public function cotizaciones(ListadoCotizacionesRequest $request): View
    {
        $cotizaciones = $request->user()->cotizaciones()
            ->with('cliente')
            ->porFacturar()
            ->soloLineasDeCatalogo()
            ->filtrar(['texto' => $request->texto(), 'folio' => $request->folio()])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $datos = ['cotizaciones' => $cotizaciones, 'texto' => $request->texto()];

        return $request->boolean('fragmento')
            ? view('facturas._cotizaciones-facturables', $datos)
            : view('facturas.cotizaciones', $datos);
    }

    /**
     * Si hay una cancelación en curso, se consulta a facturapi.io antes de
     * mostrarla. Si no responde, se muestra el último estado conocido.
     */
    public function show(Factura $factura, CanceladorFacturas $cancelador): View
    {
        Gate::authorize('view', $factura);

        $sinConsulta = $factura->cancelacionEnCurso() && ! $cancelador->refrescar($factura);

        $factura->load(['cliente', 'lineas', 'complementoPago', 'sustituta', 'cotizacion', 'pedido', 'duplicadaDe']);

        return view('facturas.show', [
            'factura' => $factura,
            'sinConsultaCancelacion' => $sinConsulta,
            'motivosCancelacion' => MotivoCancelacion::opciones(),
            'sustitutas' => $factura->puedeCancelarse() ? $this->sustitutas($factura) : [],
            'formasPagoComplemento' => array_diff_key(FormaPago::opciones(), [FormaPago::PorDefinir->value => true]),
            'hoy' => now(config('app.zona_negocio'))->toDateString(),
            'clientes' => $this->clientes($factura->user),
        ]);
    }

    public function edit(Request $request, Factura $factura): View
    {
        Gate::authorize('update', $factura);

        return view('facturas.editar', $this->datosFormulario($request, $factura));
    }

    /**
     * FacturaRequest ya verificó que es del usuario y que se puede corregir.
     */
    public function update(FacturaRequest $request, Factura $factura): RedirectResponse
    {
        DB::transaction(function () use ($request, $factura) {
            $factura->fill($request->datosFactura());
            $factura->aplicarTotales($request->totales());
            $factura->save();

            $this->guardarLineas($factura, $request);
        });

        return $this->respuestaTimbrado($factura, $this->timbrador->timbrar($factura));
    }

    /**
     * Borrado físico (antes de timbrar no hay nada fiscal que preservar): se
     * lleva las líneas.
     */
    public function destroy(Factura $factura): RedirectResponse
    {
        $respuesta = Gate::inspect('delete', $factura);

        if ($respuesta->status() === 404) {
            abort(404);
        }

        if ($respuesta->denied()) {
            return back()->with('error', $respuesta->message());
        }

        $factura->delete();

        return redirect()->route('facturas.index')
            ->with('exito', "Factura {$factura->folio_formateado} eliminada.");
    }

    /**
     * Reintento con los mismos datos de una factura pendiente.
     */
    public function timbrar(Request $request, Factura $factura): RedirectResponse
    {
        Gate::authorize('operar', $factura);

        if (! $factura->puedeReintentarse()) {
            return back()->with('error', 'Solo se reintenta el timbrado de una factura pendiente.');
        }

        return $this->respuestaTimbrado($factura, $this->timbrador->timbrar($factura), $this->vieneDelMostrador($request));
    }

    public function cancelar(CancelarFacturaRequest $request, Factura $factura, CanceladorFacturas $cancelador): RedirectResponse
    {
        if (! $factura->puedeCancelarse()) {
            return back()->with('error', 'Esta factura no se puede cancelar en su estado actual.');
        }

        try {
            $estado = $cancelador->cancelar($factura, $request->motivo(), $request->sustituta());
        } catch (FacturapiException $error) {
            return back()->withInput()->withErrors(['motivo_cancelacion' => 'facturapi.io no canceló la factura: '.$error->getMessage()], 'cancelacion');
        }

        return redirect()->route('facturas.show', $factura)->with('exito', $estado === EstadoCancelacion::Aceptada
            ? 'Factura cancelada.'
            : 'Cancelación en proceso: el receptor o el SAT deben confirmarla. El estado se actualiza al volver a abrir la factura.');
    }

    /**
     * XML consultado en vivo a facturapi.io: no se guarda copia.
     */
    public function xml(Factura $factura, FacturapiCliente $facturapi): Response|RedirectResponse
    {
        Gate::authorize('operar', $factura);

        if (! $factura->tieneDocumentoFiscal()) {
            return back()->with('error', 'La factura todavía no está timbrada: no tiene XML.');
        }

        try {
            $xml = $facturapi->descargarXml($factura->facturapi_invoice_id, $factura->id);
        } catch (FacturapiException) {
            return redirect()->route('facturas.show', $factura)->with('error', 'No se pudo obtener el XML de facturapi.io. Intenta de nuevo.');
        }

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="'.$factura->nombreArchivo('xml').'"',
        ]);
    }

    /**
     * PDF al vuelo con los datos guardados: en el navegador, o como descarga
     * con ?descargar=1.
     */
    public function pdf(Request $request, Factura $factura, GeneradorPdfFactura $generador): Response|RedirectResponse
    {
        Gate::authorize('operar', $factura);

        if (! $factura->tieneDocumentoFiscal()) {
            return back()->with('error', 'La factura todavía no está timbrada: no tiene PDF.');
        }

        $pdf = $generador->generar($factura);
        $nombre = $factura->nombreArchivo('pdf');

        return $request->boolean('descargar') ? $pdf->download($nombre) : $pdf->stream($nombre);
    }

    /**
     * A dónde lleva cada resultado del timbrado: al detalle si se timbró o si
     * falló el PAC (se reintenta igual), al formulario si facturapi.io rechazó
     * los datos (se corrigen). Desde el mostrador (033), siempre a su pantalla
     * de resultado, que lee el estado de la factura.
     */
    private function respuestaTimbrado(Factura $factura, ResultadoTimbrado $resultado, bool $mostrador = false): RedirectResponse
    {
        $factura->refresh();

        $ruta = match (true) {
            $mostrador => 'mostrador.factura.listo',
            $resultado === ResultadoTimbrado::ErrorDatos && $factura->esEditable() => 'facturas.edit',
            default => 'facturas.show',
        };
        $redireccion = redirect()->route($ruta, $factura);
        $mensaje = $this->mensajeTimbrado($factura, $resultado);

        return match ($resultado) {
            ResultadoTimbrado::Timbrada => $redireccion->with('exito', $mensaje),
            ResultadoTimbrado::SinCambio => $redireccion,
            default => $redireccion->with('error', $mensaje),
        };
    }

    /**
     * El aviso de cada resultado del timbrado (null si no hubo cambio). Lo
     * usan la redirección de store y la respuesta JSON del timbrado directo.
     */
    private function mensajeTimbrado(Factura $factura, ResultadoTimbrado $resultado): ?string
    {
        return match ($resultado) {
            ResultadoTimbrado::Timbrada => "Factura timbrada. Folio fiscal {$factura->folioFiscal()}, UUID {$factura->uuid_fiscal}.",
            ResultadoTimbrado::ErrorDatos => "facturapi.io rechazó la factura {$factura->folio_formateado}: {$factura->error_timbrado} Corrige los datos y vuelve a timbrar.",
            ResultadoTimbrado::ErrorPac => "No se pudo timbrar la factura {$factura->folio_formateado}: {$factura->error_timbrado} Quedó pendiente; reintenta el timbrado.",
            ResultadoTimbrado::EnCurso => 'Esta factura ya se está timbrando. Espera unos segundos y vuelve a abrirla.',
            ResultadoTimbrado::SinCambio => null,
        };
    }

    /**
     * Reemplaza las líneas. Las claves SAT se copian del artículo en este
     * momento; importe e IVA salen de la calculadora.
     */
    private function guardarLineas(Factura $factura, FacturaRequest $request): void
    {
        $articulos = $request->copiasFiscales();
        $totales = $request->totales();

        $factura->lineas()->delete();

        foreach ($request->lineas() as $i => $linea) {
            $articulo = $articulos->get($linea['articulo_id']);

            $factura->lineas()->create([
                ...$linea,
                'orden' => $i + 1,
                'importe' => $totales['lineas'][$i]['importe'],
                'iva_importe' => $totales['lineas'][$i]['iva_importe'],
                'clave_prod_serv' => $articulo->clave_prod_serv,
                'clave_unidad' => $articulo->clave_unidad,
                'objeto_imp' => $articulo->objeto_imp,
            ]);
        }
    }

    /**
     * Otras facturas timbradas del usuario, para el motivo 01.
     *
     * @return array<int, string>
     */
    private function sustitutas(Factura $factura): array
    {
        return $factura->user->facturas()
            ->with('cliente')
            ->where('estado', EstadoFactura::Timbrada)
            ->whereKeyNot($factura->id)
            ->orderByDesc('fecha_timbrado')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn (Factura $otra) => [$otra->id => $otra->folioVisible().' — '.$otra->cliente->razon_social.' — $'.number_format((float) $otra->total, 2)])
            ->all();
    }

    /**
     * @return LengthAwarePaginator<int, Factura>
     */
    private function facturas(ListadoFacturasRequest $request): LengthAwarePaginator
    {
        return $request->user()->facturas()
            ->with('cliente')
            ->filtrar($request->filtros())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(ListadoFacturasRequest::POR_PAGINA)
            ->withQueryString();
    }

    /**
     * @param  LengthAwarePaginator<int, Factura>  $facturas
     * @return array<string, mixed>
     */
    private function datosListado(ListadoFacturasRequest $request, LengthAwarePaginator $facturas): array
    {
        return [
            'facturas' => $facturas,
            'campos' => $request->campos(),
            'hayFiltros' => $request->parametros() !== [],
        ];
    }

    /**
     * El formulario lleno con la cotización tal como está: cliente, descuento
     * global y líneas (con sus precios cotizados). El descuento de cada línea
     * llega dentro de su precio y no por separado (023). Avisa de los precios
     * que cambiaron desde entonces en el catálogo, sin cambiar la línea.
     *
     * @return array<string, mixed>
     */
    private function precargaDeCotizacion(Cotizacion $cotizacion): array
    {
        $cotizacion->loadMissing('lineas.articulo');

        return [
            'cabecera' => [
                'cliente_id' => $cotizacion->cliente_id,
                'descuento_global_tipo' => $cotizacion->descuento_global_tipo?->value,
                'descuento_global_valor' => $cotizacion->descuento_global_valor,
            ],
            // Sin descuento de línea: va dentro del precio (023).
            'lineas' => $cotizacion->lineas->map(fn (CotizacionLinea $linea) => [...$linea->datosParaFactura(), 'importe' => $linea->importe])->all(),
            'cotizacion' => $cotizacion,
            'avisosPrecio' => $cotizacion->avisosDePrecio(),
        ];
    }

    /**
     * El formulario lleno con una copia de la factura para el cliente elegido
     * (si es ajeno o está eliminado queda sin elegir). Se omiten las líneas de
     * artículos eliminados: una factura nueva no los acepta.
     *
     * @return array<string, mixed>
     */
    private function precargaDeFactura(Request $request, Factura $original): array
    {
        $original->loadMissing('lineas.articulo');

        [$vigentes, $omitidas] = $original->lineas->partition(fn (FacturaLinea $linea) => $linea->articulo !== null && ! $linea->articulo->trashed());

        return [
            'cabecera' => [
                'cliente_id' => $request->user()->clientes()->find($request->integer('cliente_id'))?->id,
                'uso_cfdi' => $original->uso_cfdi->value,
                'metodo_pago' => $original->metodo_pago->value,
                'forma_pago' => $original->forma_pago->value,
                'descuento_global_tipo' => $original->descuento_global_tipo?->value,
                'descuento_global_valor' => $original->descuento_global_valor,
            ],
            'lineas' => $vigentes->map($this->lineaFormulario(...))->values()->all(),
            'duplicadaDe' => $original,
            'lineasOmitidas' => $omitidas->pluck('descripcion')->all(),
        ];
    }

    /**
     * Una línea guardada de factura como la pinta el formulario.
     *
     * @return array<string, mixed>
     */
    private function lineaFormulario(FacturaLinea $linea): array
    {
        return [
            'articulo_id' => $linea->articulo_id,
            'cantidad' => $linea->cantidad,
            'descripcion' => $linea->descripcion,
            'modelo' => $linea->modelo,
            'precio_unitario' => $linea->precio_unitario,
            'descuento_tipo' => $linea->descuento_tipo?->value,
            'descuento_valor' => $linea->descuento_valor,
            'tasa_iva' => $linea->tasa_iva->value,
            'importe' => $linea->importe,
        ];
    }

    /**
     * Clientes activos del usuario para los selects ("Razón social — RFC").
     *
     * @return array<int, string>
     */
    private function clientes(User $user): array
    {
        return $user->clientes()->orderBy('razon_social')->get()
            ->mapWithKeys(fn ($cliente) => [$cliente->id => $cliente->razon_social.' — '.$cliente->rfc])->all();
    }

    /**
     * Líneas a pintar: las del intento fallido (old), las guardadas, las de
     * la precarga (cotización o factura duplicada), o ninguna en el alta.
     *
     * @param  array<string, mixed>  $precarga
     * @return array<string, mixed>
     */
    private function datosFormulario(Request $request, ?Factura $factura = null, array $precarga = []): array
    {
        $lineas = $request->old('lineas');

        if (! is_array($lineas)) {
            $lineas = $factura?->lineas->map($this->lineaFormulario(...))->all() ?? $precarga['lineas'] ?? [];
        }

        $cabecera = $precarga['cabecera'] ?? [];
        $clientes = $request->user()->clientes()->orderBy('razon_social')->get();
        // La factura de una cotización conserva el precio cotizado (028): no
        // se vuelve a decidir si el cliente es distribuidor.
        $deCotizacion = isset($precarga['cotizacion']) || $factura?->cotizacion_id !== null || $request->old('cotizacion_id') !== null;

        return [
            'factura' => $factura,
            'lineas' => Articulo::conPreciosDeVenta($request->user(), array_values(array_filter($lineas, 'is_array'))),
            // Lo que lee documento-lineas.js para elegir el precio distribuidor; null lo desactiva.
            'distribuidores' => $deCotizacion ? null : $clientes->where('es_distribuidor', true)->pluck('razon_social', 'id')->all(),
            'cabecera' => [
                'cliente_id' => $factura?->cliente_id ?? $cabecera['cliente_id'] ?? null,
                'uso_cfdi' => $factura?->uso_cfdi?->value ?? $cabecera['uso_cfdi'] ?? UsoCfdi::GastosEnGeneral->value,
                'metodo_pago' => $factura?->metodo_pago?->value ?? $cabecera['metodo_pago'] ?? MetodoPago::UnaExhibicion->value,
                'forma_pago' => $factura?->forma_pago?->value ?? $cabecera['forma_pago'] ?? null,
                'descuento_global_tipo' => $factura ? $factura->descuento_global_tipo?->value : $cabecera['descuento_global_tipo'] ?? null,
                'descuento_global_valor' => $factura ? $factura->descuento_global_valor : $cabecera['descuento_global_valor'] ?? null,
            ],
            'cotizacionOrigen' => $precarga['cotizacion'] ?? null,
            'facturaOrigen' => $precarga['duplicadaDe'] ?? null,
            'avisosPrecio' => $precarga['avisosPrecio'] ?? [],
            'lineasOmitidas' => $precarga['lineasOmitidas'] ?? [],
            'clientes' => $clientes->mapWithKeys(fn ($cliente) => [$cliente->id => $cliente->razon_social.' — '.$cliente->rfc])->all(),
            'usosCfdi' => UsoCfdi::opcionesFactura(),
            'metodosPago' => MetodoPago::opciones(),
            'formasPago' => FormaPago::opciones(),
            'tiposDescuento' => TipoDescuento::opciones(),
            'tasasIva' => TasaIva::opciones(),
        ];
    }
}
