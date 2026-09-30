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
use App\Http\Requests\ListadoFacturasRequest;
use App\Models\Factura;
use App\Models\FacturaLinea;
use App\Models\User;
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

    public function create(Request $request): View
    {
        return view('facturas.crear', $this->datosFormulario($request));
    }

    /**
     * Guarda la captura completa (folio, factura y líneas) y, ya confirmada la
     * transacción, intenta timbrar. Si el timbrado falla la factura queda
     * pendiente: la captura no se pierde.
     */
    public function store(FacturaRequest $request): RedirectResponse
    {
        $factura = DB::transaction(function () use ($request) {
            $factura = $request->user()->facturas()->make($request->datosFactura());
            $factura->folio = $this->siguienteFolio($request->user());
            $factura->aplicarTotales($request->totales());
            $factura->save();

            $this->guardarLineas($factura, $request);

            return $factura;
        });

        return $this->respuestaTimbrado($factura, $this->timbrador->timbrar($factura));
    }

    /**
     * Si hay una cancelación en curso, se consulta a facturapi.io antes de
     * mostrarla. Si no responde, se muestra el último estado conocido.
     */
    public function show(Factura $factura, CanceladorFacturas $cancelador): View
    {
        Gate::authorize('view', $factura);

        $sinConsulta = $factura->cancelacionEnCurso() && ! $cancelador->refrescar($factura);

        $factura->load(['cliente', 'lineas', 'complementoPago', 'sustituta']);

        return view('facturas.show', [
            'factura' => $factura,
            'sinConsultaCancelacion' => $sinConsulta,
            'motivosCancelacion' => MotivoCancelacion::opciones(),
            'sustitutas' => $factura->puedeCancelarse() ? $this->sustitutas($factura) : [],
            'formasPagoComplemento' => array_diff_key(FormaPago::opciones(), [FormaPago::PorDefinir->value => true]),
            'hoy' => now(config('app.zona_negocio'))->toDateString(),
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
            ResultadoTimbrado::ErrorDatos => redirect()->route('facturas.edit', $factura)
                ->with('error', "facturapi.io rechazó la factura {$factura->folio_formateado}: {$factura->error_timbrado} Corrige los datos y vuelve a timbrar."),
            ResultadoTimbrado::ErrorPac => redirect()->route('facturas.show', $factura)
                ->with('error', "No se pudo timbrar la factura {$factura->folio_formateado}: {$factura->error_timbrado} Quedó pendiente; reintenta el timbrado."),
            ResultadoTimbrado::EnCurso => redirect()->route('facturas.show', $factura)
                ->with('error', 'Esta factura ya se está timbrando. Espera unos segundos y vuelve a abrirla.'),
            ResultadoTimbrado::SinCambio => redirect()->route('facturas.show', $factura),
        };
    }

    /**
     * Consecutivo por usuario que nunca se reutiliza (mismo mecanismo que el
     * folio de cotización).
     */
    private function siguienteFolio(User $user): int
    {
        $bloqueado = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        $folio = max($bloqueado->ultimo_folio_factura, (int) $bloqueado->facturas()->max('folio')) + 1;

        $bloqueado->forceFill(['ultimo_folio_factura' => $folio])->save();

        return $folio;
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
     * Líneas a pintar: las del intento fallido (old), las guardadas, o
     * ninguna en el alta.
     *
     * @return array<string, mixed>
     */
    private function datosFormulario(Request $request, ?Factura $factura = null): array
    {
        $lineas = $request->old('lineas');

        if (! is_array($lineas)) {
            $lineas = $factura?->lineas->map(fn (FacturaLinea $linea) => [
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
            'factura' => $factura,
            'lineas' => array_values(array_filter($lineas, 'is_array')),
            'clientes' => $request->user()->clientes()->orderBy('razon_social')->get()
                ->mapWithKeys(fn ($cliente) => [$cliente->id => $cliente->razon_social.' — '.$cliente->rfc])->all(),
            'usosCfdi' => UsoCfdi::opcionesFactura(),
            'metodosPago' => MetodoPago::opciones(),
            'formasPago' => FormaPago::opciones(),
            'tiposDescuento' => TipoDescuento::opciones(),
            'tasasIva' => TasaIva::opciones(),
        ];
    }
}
