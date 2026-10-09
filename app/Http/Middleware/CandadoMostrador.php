<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Modo mostrador (033): con la sesión marcada por /mostrador (la start_url de
 * la aplicación instalada), solo se alcanzan las pantallas del mostrador y las
 * rutas que usan sus tres capturas y su consulta (034: facturar y cobrar una
 * cotización). Lo demás regresa a los tres accesos.
 */
class CandadoMostrador
{
    /**
     * Clave de sesión que enciende el modo. Solo la escribe MostradorController@inicio.
     */
    public const SESION = 'mostrador';

    /**
     * Rutas permitidas con el candado encendido.
     *
     * @var list<string>
     */
    public const RUTAS = [
        'mostrador.*',
        'logout',
        'clientes.store',
        'clientes.constancia',
        'articulos.imagen',
        'pedidos.cliente-por-telefono',
        'pedidos.store',
        'pedidos.pagos.store',
        'pedidos.ticket',
        'cotizaciones.store',
        'cotizaciones.pdf',
        'cotizaciones.enviar',
        'cotizaciones.marcar-enviada',
        'cotizaciones.timbrar',
        'cotizaciones.pagos.store',
        'facturas.store',
        'facturas.timbrar',
        'facturas.pdf',
        'facturas.enviar',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->get(self::SESION) || $request->routeIs(...self::RUTAS)) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            return redirect()->route('mostrador.inicio');
        }

        abort(403, 'Esta acción no está disponible en el mostrador.');
    }
}
