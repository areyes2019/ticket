<?php

namespace App\Http\Controllers;

use App\Enums\EstadoFactura;
use App\Enums\FormaPago;
use App\Enums\MetodoPago;
use App\Enums\TipoErrorTimbrado;
use App\Enums\TipoPago;
use App\Enums\UsoCfdi;
use App\Http\Controllers\Concerns\PaginaTarjetasMostrador;
use App\Models\Articulo;
use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\Factura;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * La consulta del mostrador (034): cotizaciones, facturas y catálogo desde el
 * celular. Nada se edita aquí: facturar y cobrar una cotización son pantallas
 * que envían a las rutas de siempre (cotizaciones.timbrar y
 * cotizaciones.pagos.store) con origen=mostrador.
 */
class MostradorConsultaController extends Controller
{
    use PaginaTarjetasMostrador;

    public function cotizaciones(Request $request): View
    {
        return $this->lista('cotizaciones', $this->paginaCotizaciones($request));
    }

    public function facturas(Request $request): View
    {
        return $this->lista('facturas', $this->paginaFacturas($request));
    }

    public function catalogo(Request $request): View
    {
        return $this->lista('catalogo', $this->paginaCatalogo($request));
    }

    /**
     * El detalle de la cotización, que también es donde termina la captura
     * de una cotización en el mostrador.
     */
    public function cotizacion(Cotizacion $cotizacion): View
    {
        Gate::authorize('operar', $cotizacion);

        $cotizacion->load(['cliente', 'lineas', 'pagos', 'facturaVigente', 'venta.facturaVigente']);

        return view('mostrador.cotizacion', [
            'cotizacion' => $cotizacion,
            'facturar' => self::estadoFacturar($cotizacion),
            // Con la cotización ya sin opción de pago, motivoRechazoPago() dice por qué.
            'motivoSinPago' => $cotizacion->puedeRegistrarPago() ? null : $cotizacion->motivoRechazoPago(TipoPago::PagoTotal, null),
        ]);
    }

    /**
     * Los pasos fiscales para timbrar la cotización tal como está: cliente,
     * descuento y renglones los toma TimbrarCotizacionRequest de ella.
     */
    public function facturar(Request $request, Cotizacion $cotizacion): View|RedirectResponse
    {
        Gate::authorize('operar', $cotizacion);

        $motivo = $cotizacion->motivoNoFacturable();

        if ($motivo !== null) {
            return redirect()->route('mostrador.cotizaciones.ver', $cotizacion)->with('error', $motivo);
        }

        $cotizacion->load('cliente');

        return view('mostrador.facturar', [
            'cotizacion' => $cotizacion,
            'avisos' => $cotizacion->avisosDePrecio(),
            'anterior' => $request->old() === [] ? null : $request->old(),
            'usos' => UsoCfdi::deFactura(),
            'formas' => FormaPago::cases(),
            'metodos' => MetodoPago::cases(),
        ]);
    }

    /**
     * Registrar un pago con la forma del cobro de la venta (033): saldo ya
     * escrito, fecha de hoy y la caja preseleccionada. Si este pago crea la
     * venta (029), también su contacto.
     */
    public function pago(Request $request, Cotizacion $cotizacion): View|RedirectResponse
    {
        Gate::authorize('operar', $cotizacion);

        $cotizacion->load(['cliente', 'pagos', 'venta']);

        if (! $cotizacion->puedeRegistrarPago()) {
            return redirect()->route('mostrador.cotizaciones.ver', $cotizacion)
                ->with('error', $cotizacion->motivoRechazoPago(TipoPago::PagoTotal, null));
        }

        $cuentas = $request->user()->cuentas()->activas()->orderBy('nombre')->get(['id', 'nombre', 'tipo']);

        return view('mostrador.pago', [
            'cotizacion' => $cotizacion,
            'cuentas' => $cuentas,
            'cuentaElegida' => old('cuenta_id', Cuenta::cajaEntre($cuentas)?->id),
            'destino' => $cotizacion->destinoAlCobrar(),
            'tieneAnticipo' => $cotizacion->tieneAnticipo(),
            'hoy' => now(config('app.zona_negocio'))->toDateString(),
        ]);
    }

    /**
     * El detalle de la factura, que también es donde termina el timbrado
     * hecho en el mostrador.
     */
    public function factura(Factura $factura): View
    {
        Gate::authorize('operar', $factura);

        $factura->load(['cliente', 'lineas', 'cotizacion']);

        return view('mostrador.factura', [
            'factura' => $factura,
            'timbrada' => $factura->estado === EstadoFactura::Timbrada,
            'cancelada' => $factura->estado === EstadoFactura::Cancelada,
            'reintentable' => $factura->puedeReintentarse() && $factura->tipo_error_timbrado !== TipoErrorTimbrado::Datos,
        ]);
    }

    /**
     * La ficha que se le enseña al cliente: nunca costo, utilidad,
     * existencias ni precio distribuidor.
     */
    public function articulo(Articulo $articulo): View
    {
        Gate::authorize('view', $articulo);

        return view('mostrador.articulo', ['articulo' => $articulo]);
    }

    /**
     * Qué hace el botón "Facturar" del detalle, con la regla del escritorio
     * (motivoNoFacturable). Una factura sin timbrar se abre para reintentar en
     * lugar de crear otra; una cancelada no cuenta (facturaVigente).
     *
     * @return array{accion: 'facturar'|'ver'|'apagado', motivo: string|null, factura: Factura|null}
     */
    public static function estadoFacturar(Cotizacion $cotizacion): array
    {
        $vigente = $cotizacion->facturaVigente;

        if ($vigente !== null) {
            return in_array($vigente->estado, [EstadoFactura::Borrador, EstadoFactura::Pendiente], true)
                ? ['accion' => 'ver', 'motivo' => "Su factura {$vigente->folioVisible()} quedó sin timbrar.", 'factura' => $vigente]
                : ['accion' => 'apagado', 'motivo' => "Ya facturada en {$vigente->folioVisible()}.", 'factura' => $vigente];
        }

        $motivo = $cotizacion->motivoNoFacturable();

        return $motivo === null
            ? ['accion' => 'facturar', 'motivo' => null, 'factura' => null]
            : ['accion' => 'apagado', 'motivo' => $motivo, 'factura' => $cotizacion->facturaDeLaVenta()];
    }

    /**
     * @param  array{elementos: mixed, siguiente: string|null, q: string}  $pagina
     */
    private function lista(string $seccion, array $pagina): View
    {
        return view('mostrador.lista', [...$pagina, 'seccion' => $seccion]);
    }
}
