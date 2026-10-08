<?php

namespace App\Http\Controllers;

use App\Enums\FormaPago;
use App\Enums\MetodoPago;
use App\Enums\RegimenFiscal;
use App\Enums\TasaIva;
use App\Enums\UsoCfdi;
use App\Http\Middleware\CandadoMostrador;
use App\Models\Articulo;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * La aplicación de mostrador (033): la pantalla de los tres accesos y las
 * capturas por pasos. Ninguna acción guarda nada: cada captura termina en un
 * envío normal a la ruta de alta de siempre, con origen=mostrador.
 */
class MostradorController extends Controller
{
    /**
     * Los flujos que eligen cliente del catálogo fiscal.
     */
    public const FLUJOS_CON_CLIENTE = ['factura', 'cotizacion'];

    /**
     * Es la start_url de la aplicación instalada: abrirla enciende el candado.
     */
    public function inicio(Request $request): View
    {
        $request->session()->put(CandadoMostrador::SESION, true);

        return view('mostrador.inicio');
    }

    public function venta(Request $request): View
    {
        return $this->captura($request, 'venta');
    }

    public function factura(Request $request): View
    {
        return $this->captura($request, 'factura');
    }

    public function cotizacion(Request $request): View
    {
        return $this->captura($request, 'cotizacion');
    }

    /**
     * Alta de cliente con la constancia o a mano. Guarda en clientes.store y
     * regresa a la captura con el cliente elegido.
     */
    public function clienteNuevo(string $flujo): View
    {
        return view('mostrador.cliente-nuevo', [
            'flujo' => $flujo,
            'regimenes' => RegimenFiscal::opciones(),
        ]);
    }

    private function captura(Request $request, string $flujo): View
    {
        $user = $request->user();
        $anterior = $request->old();
        $lineas = is_array($anterior['lineas'] ?? null) ? array_values(array_filter($anterior['lineas'], 'is_array')) : [];

        // Tras un error de validación manda lo enviado; si no, el que llega del alta de cliente.
        $clienteId = $anterior['cliente_id'] ?? $request->query('cliente');
        $cliente = in_array($flujo, self::FLUJOS_CON_CLIENTE, true) && is_numeric($clienteId)
            ? $user->clientes()->find((int) $clienteId)
            : null;

        return view('mostrador.captura', [
            'flujo' => $flujo,
            'anterior' => $anterior === [] ? null : [
                ...$anterior,
                'lineas' => Articulo::conPreciosDeVenta($user, $lineas),
            ],
            'cliente' => $cliente === null ? null : self::datosCliente($cliente),
            'tasasIva' => TasaIva::opciones(),
            'usos' => UsoCfdi::deFactura(),
            'formas' => FormaPago::cases(),
            'metodos' => MetodoPago::cases(),
        ]);
    }

    /**
     * Lo que la captura necesita de un cliente: lo pintan las tarjetas en
     * data-* y lo lee mostrador.js. El descuento solo aplica en la cotización
     * (023) y el precio distribuidor en cotización y factura (028), igual que
     * en el escritorio; esa decisión la toma mostrador.js con el flujo.
     *
     * @return array{id: int, razon_social: string, rfc: string, descuento: string, distribuidor: bool, correo: string|null}
     */
    public static function datosCliente(Cliente $cliente): array
    {
        return [
            'id' => $cliente->id,
            'razon_social' => $cliente->razon_social,
            'rfc' => $cliente->rfc,
            'descuento' => $cliente->tieneDescuentoPermanente() ? Cliente::porcentajeTexto($cliente->descuento_permanente) : '',
            'distribuidor' => (bool) $cliente->es_distribuidor,
            'correo' => $cliente->correo,
        ];
    }
}
