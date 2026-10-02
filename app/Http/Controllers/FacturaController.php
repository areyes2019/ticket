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
use App\Http\Requests\CancelarFacturaRequest;
use App\Http\Requests\FacturaRequest;
use App\Http\Requests\ListadoCotizacionesRequest;
use App\Http\Requests\ListadoFacturasRequest;
use App\Models\Cotizacion;
use App\Models\CotizacionLinea;
use App\Models\Factura;
use App\Models\FacturaLinea;
use App\Models\User;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use App\Services\Facturacion\CanceladorFacturas;
use App\Services\Facturacion\FacturapiCliente;
use App\Services\Facturacion\FacturapiException;
use App\Services\Facturacion\GeneradorPdfFactura;
use App\Services\Facturacion\ResultadoTimbrado;
use App\Services\Facturacion\TimbradorFacturas;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class FacturaController extends Controller
{
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
     *
     * Si sale de una cotización, la fila de la cotización se bloquea y se
     * vuelve a revisar: dos clics o dos pestañas no crean dos facturas.
     */
    public function store(FacturaRequest $request): RedirectResponse
    {
        $origen = $request->origen();

        $resultado = DB::transaction(function () use ($request, $origen): Factura|RedirectResponse {
            if ($origen['cotizacion_id'] !== null) {
                $cotizacion = Cotizacion::whereKey($origen['cotizacion_id'])->lockForUpdate()->firstOrFail();
                $vigente = $cotizacion->facturaVigente()->first();

                if ($vigente !== null) {
                    return redirect()->route('facturas.show', $vigente)
                        ->with('error', "Esta cotización ya se facturó en {$vigente->folioVisible()}.");
                }

                $motivo = $cotizacion->motivoNoFacturable();

                if ($motivo !== null) {
                    return back()->withInput()->with('error', $motivo);
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

        if ($resultado instanceof RedirectResponse) {
            return $resultado;
        }

        return $this->respuestaTimbrado($resultado, $this->timbrador->timbrar($resultado));
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
    public function timbrar(Factura $factura): RedirectResponse
    {
        Gate::authorize('operar', $factura);

        if (! $factura->puedeReintentarse()) {
            return back()->with('error', 'Solo se reintenta el timbrado de una factura pendiente.');
        }

        return $this->respuestaTimbrado($factura, $this->timbrador->timbrar($factura));
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
     * los datos (se corrigen).
     */
    private function respuestaTimbrado(Factura $factura, ResultadoTimbrado $resultado): RedirectResponse
    {
        $factura->refresh();

        return match ($resultado) {
            ResultadoTimbrado::Timbrada => redirect()->route('facturas.show', $factura)
                ->with('exito', "Factura timbrada. Folio fiscal {$factura->folioFiscal()}, UUID {$factura->uuid_fiscal}."),
            ResultadoTimbrado::ErrorDatos => redirect()->route($factura->esEditable() ? 'facturas.edit' : 'facturas.show', $factura)
                ->with('error', "facturapi.io rechazó la factura {$factura->folio_formateado}: {$factura->error_timbrado} Corrige los datos y vuelve a timbrar."),
            ResultadoTimbrado::ErrorPac => redirect()->route('facturas.show', $factura)
                ->with('error', "No se pudo timbrar la factura {$factura->folio_formateado}: {$factura->error_timbrado} Quedó pendiente; reintenta el timbrado."),
            ResultadoTimbrado::EnCurso => redirect()->route('facturas.show', $factura)
                ->with('error', 'Esta factura ya se está timbrando. Espera unos segundos y vuelve a abrirla.'),
            ResultadoTimbrado::SinCambio => redirect()->route('facturas.show', $factura),
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
     * global y líneas (con sus precios cotizados). Avisa de los precios que
     * cambiaron desde entonces en el catálogo, sin cambiar la línea.
     *
     * @return array<string, mixed>
     */
    private function precargaDeCotizacion(Cotizacion $cotizacion): array
    {
        $cotizacion->loadMissing('lineas.articulo');
        $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);

        $avisos = $cotizacion->lineas
            ->filter(fn (CotizacionLinea $linea) => CalculadoraTotalesDocumento::centavos($linea->precio_unitario) !== CalculadoraTotalesDocumento::centavos($linea->articulo->precio_unitario_sin_iva))
            ->map(fn (CotizacionLinea $linea) => "{$linea->descripcion}: {$pesos($linea->precio_unitario)} en la cotización, {$pesos($linea->articulo->precio_unitario_sin_iva)} hoy en el catálogo.")
            ->values()
            ->all();

        return [
            'cabecera' => [
                'cliente_id' => $cotizacion->cliente_id,
                'descuento_global_tipo' => $cotizacion->descuento_global_tipo?->value,
                'descuento_global_valor' => $cotizacion->descuento_global_valor,
            ],
            'lineas' => $cotizacion->lineas->map($this->lineaFormulario(...))->all(),
            'cotizacion' => $cotizacion,
            'avisosPrecio' => $avisos,
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
     * Una línea guardada (de factura o de cotización) como la pinta el
     * formulario.
     *
     * @return array<string, mixed>
     */
    private function lineaFormulario(FacturaLinea|CotizacionLinea $linea): array
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

        return [
            'factura' => $factura,
            'lineas' => array_values(array_filter($lineas, 'is_array')),
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
            'clientes' => $this->clientes($request->user()),
            'usosCfdi' => UsoCfdi::opcionesFactura(),
            'metodosPago' => MetodoPago::opciones(),
            'formasPago' => FormaPago::opciones(),
            'tiposDescuento' => TipoDescuento::opciones(),
            'tasasIva' => TasaIva::opciones(),
        ];
    }
}
