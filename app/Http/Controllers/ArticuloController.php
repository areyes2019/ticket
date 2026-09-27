<?php

namespace App\Http\Controllers;

use App\Enums\ObjetoImpuesto;
use App\Http\Requests\ArticuloRequest;
use App\Http\Requests\ListadoArticulosRequest;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\SatClaveProdServ;
use App\Models\SatClaveUnidad;
use App\Services\Articulos\CalculadoraPrecioArticulo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ArticuloController extends Controller
{
    /**
     * Página completa del listado, con filtros, orden y página de la URL.
     */
    public function index(ListadoArticulosRequest $request): View
    {
        return view('articulos.index', $this->datosListado($request, $this->articulos($request)));
    }

    /**
     * Solo lo que cambia al buscar u ordenar, para la búsqueda dinámica (AJAX).
     * Los enlaces de página apuntan al listado completo para que se puedan
     * abrir sin JavaScript o compartir.
     */
    public function buscar(ListadoArticulosRequest $request): View
    {
        $articulos = $this->articulos($request)->withPath(route('articulos.index'));

        return view('articulos._resultados', $this->datosListado($request, $articulos));
    }

    public function create(Request $request): View
    {
        return view('articulos.crear', $this->datosFormulario($request));
    }

    public function store(ArticuloRequest $request): RedirectResponse
    {
        $articulo = $request->user()->articulos()->create($request->validated());

        return redirect()->route('articulos.index')->with('exito', 'Artículo creado. '.$this->precioGuardado($articulo));
    }

    public function edit(Request $request, Articulo $articulo): View
    {
        Gate::authorize('update', $articulo);

        return view('articulos.editar', $this->datosFormulario($request, $articulo));
    }

    /**
     * ArticuloRequest ya verificó que el artículo es del usuario.
     */
    public function update(ArticuloRequest $request, Articulo $articulo): RedirectResponse
    {
        $articulo->update($request->validated());

        return redirect()->route('articulos.index')->with('exito', 'Artículo actualizado. '.$this->precioGuardado($articulo));
    }

    /**
     * El precio de venta que calculó y guardó el servidor, que es el que
     * cuenta (el resumen del formulario solo es informativo).
     */
    private function precioGuardado(Articulo $articulo): string
    {
        return 'Precio de venta con IVA: $'.number_format($articulo->precio_unitario_con_iva, 2).'.';
    }

    /**
     * Borrado lógico.
     *
     * Regla de negocio pendiente: no se debe eliminar un artículo usado en
     * líneas de factura. Se verificará aquí cuando exista el módulo de facturación.
     */
    public function destroy(Articulo $articulo): RedirectResponse
    {
        Gate::authorize('delete', $articulo);

        $articulo->delete();

        return redirect()->route('articulos.index')->with('exito', 'Artículo eliminado.');
    }

    /**
     * @return LengthAwarePaginator<int, Articulo>
     */
    private function articulos(ListadoArticulosRequest $request): LengthAwarePaginator
    {
        return $request->user()->articulos()
            ->with(['proveedor', 'catalogo'])
            ->filtrar($request->filtros())
            ->ordenar($request->orden(), $request->direccion())
            ->paginate($request->porPagina())
            ->withQueryString();
    }

    /**
     * @param  LengthAwarePaginator<int, Articulo>  $articulos
     * @return array<string, mixed>
     */
    private function datosListado(ListadoArticulosRequest $request, LengthAwarePaginator $articulos): array
    {
        return [
            'articulos' => $articulos,
            'filtros' => $request->filtros(),
            'orden' => $request->orden(),
            'direccion' => $request->direccion(),
            'porPagina' => $request->porPagina(),
            'parametros' => $request->parametros(),
        ];
    }

    /**
     * Renglones de la cadena de cálculo del formulario, con lo guardado (en
     * el alta van vacíos hasta que precio-articulo.js tiene datos).
     *
     * @return list<array{clave: string, etiqueta: string, valor: string, porcentaje?: string, total?: bool}>
     */
    private function resumenPrecio(?Articulo $articulo): array
    {
        $pesos = fn (float|string $monto, string $signo = '') => $articulo ? $signo.'$'.number_format((float) $monto, 2) : '—';
        $venta = (float) $articulo?->precio_unitario_sin_iva;
        $costo = (float) $articulo?->costo_con_descuento;
        $lista = (float) $articulo?->precio_proveedor;

        return [
            ['clave' => 'lista', 'etiqueta' => 'Precio de lista del proveedor', 'valor' => $pesos($lista)],
            ['clave' => 'descuento', 'etiqueta' => 'Descuento del catálogo', 'porcentaje' => $articulo ? $articulo->catalogo->descuento_texto : '—',
                'valor' => $pesos(CalculadoraPrecioArticulo::redondeo2($lista - $costo), '−')],
            ['clave' => 'costo', 'etiqueta' => 'Costo', 'valor' => $pesos($costo), 'total' => true],
            ['clave' => 'utilidad', 'etiqueta' => 'Utilidad', 'porcentaje' => $articulo ? Catalogo::porcentajeTexto($articulo->utilidad_porcentaje_efectivo) : '—',
                'valor' => $pesos((float) $articulo?->utilidad, '+')],
            ['clave' => 'venta', 'etiqueta' => 'Precio de venta sin IVA', 'valor' => $pesos($venta), 'total' => true],
            ['clave' => 'iva', 'etiqueta' => 'IVA ('.Articulo::TASA_IVA * 100 .'%)',
                'valor' => $pesos(CalculadoraPrecioArticulo::redondeo2((float) $articulo?->precio_unitario_con_iva - $venta), '+')],
            ['clave' => 'venta-con-iva', 'etiqueta' => 'Precio de venta con IVA', 'valor' => $pesos((float) $articulo?->precio_unitario_con_iva), 'total' => true],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function datosFormulario(Request $request, ?Articulo $articulo = null): array
    {
        $claveProdServ = old('clave_prod_serv', $articulo?->clave_prod_serv);
        $claveUnidad = old('clave_unidad', $articulo?->clave_unidad);

        $catalogos = $request->user()->catalogos()->disponibles()->get();
        $catalogoElegido = $catalogos->firstWhere('id', (int) old('catalogo_id', $articulo?->catalogo_id));

        return [
            'articulo' => $articulo,
            'catalogos' => $catalogos->pluck('etiqueta', 'id')->all(),
            'preciosCatalogo' => $catalogos->mapWithKeys(fn (Catalogo $catalogo) => [$catalogo->id => [
                'descuento' => (float) $catalogo->descuento,
                'utilidad' => (float) $catalogo->utilidad_porcentaje,
            ]])->all(),
            'placeholderUtilidad' => $catalogoElegido ? "Hereda {$catalogoElegido->utilidad_texto} del catálogo" : null,
            'resumen' => $this->resumenPrecio($articulo),
            'objetosImpuesto' => ObjetoImpuesto::opciones(),
            'descripcionProdServ' => $claveProdServ ? SatClaveProdServ::find($claveProdServ)?->descripcion : null,
            'descripcionUnidad' => $claveUnidad ? SatClaveUnidad::find(mb_strtoupper($claveUnidad))?->nombre : null,
        ];
    }
}
