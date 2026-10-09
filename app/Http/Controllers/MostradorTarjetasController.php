<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PaginaTarjetasMostrador;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Páginas de tarjetas del mostrador, como HTML pintado por Blade: las de la
 * captura (033) y las de la consulta (034). Cada página termina con
 * data-siguiente (la URL de la que sigue) para que mostrador.js cargue más al
 * llegar al final sin conocer la paginación.
 */
class MostradorTarjetasController extends Controller
{
    use PaginaTarjetasMostrador;

    public const CLIENTES_POR_PAGINA = 25;

    public const ARTICULOS_POR_PAGINA = 24;

    public function clientes(Request $request): View
    {
        $clientes = $request->user()->clientes()
            ->buscarTexto($request->string('q')->toString())
            ->orderBy('razon_social')
            ->orderBy('id')
            ->paginate(self::CLIENTES_POR_PAGINA)
            ->withQueryString();

        return view('mostrador._tarjetas-clientes', ['clientes' => $clientes]);
    }

    public function articulos(Request $request): View
    {
        $articulos = $request->user()->articulos()
            ->buscarTexto($request->string('q')->toString())
            ->orderBy('nombre')
            ->orderBy('id')
            ->paginate(self::ARTICULOS_POR_PAGINA)
            ->withQueryString();

        return view('mostrador._tarjetas-articulos', ['articulos' => $articulos]);
    }

    public function cotizaciones(Request $request): View
    {
        return view('mostrador._tarjetas-cotizaciones', $this->paginaCotizaciones($request));
    }

    public function facturas(Request $request): View
    {
        return view('mostrador._tarjetas-facturas', $this->paginaFacturas($request));
    }

    public function catalogo(Request $request): View
    {
        return view('mostrador._tarjetas-catalogo', $this->paginaCatalogo($request));
    }
}
