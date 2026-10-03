<?php

namespace App\Http\Controllers;

use App\Enums\ObjetoImpuesto;
use App\Enums\TasaIva;
use App\Http\Requests\ArticuloRequest;
use App\Http\Requests\ListadoArticulosRequest;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\SatClaveProdServ;
use App\Models\SatClaveUnidad;
use App\Services\Articulos\CalculadoraPrecioArticulo;
use App\Services\Articulos\ProcesadorImagenArticulo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /**
     * Sugerencias para las líneas de un documento (cotización): artículos del
     * usuario cuyo nombre o modelo contiene el texto, con los datos que se
     * precargan en la línea.
     */
    public function sugerencias(Request $request): JsonResponse
    {
        $termino = $request->string('q')->trim()->toString();

        if ($termino === '') {
            return response()->json([]);
        }

        // Órdenes de compra: solo artículos del proveedor (copia del proveedor
        // del catálogo) y con su costo en lugar del precio de venta.
        $costo = $request->query('precio') === 'costo';

        $articulos = $request->user()->articulos()
            ->where(fn ($consulta) => $consulta->where('nombre', 'like', "%{$termino}%")->orWhere('modelo', 'like', "%{$termino}%"))
            ->when($request->filled('proveedor_id'), fn ($consulta) => $consulta->where('proveedor_id', $request->integer('proveedor_id')))
            ->orderBy('nombre')
            ->limit(20)
            ->get();

        return response()->json($articulos->map(fn (Articulo $articulo) => [
            'id' => $articulo->id,
            'nombre' => $articulo->nombre,
            'modelo' => $articulo->modelo,
            'precio_unitario' => $costo ? $articulo->costo_con_descuento : $articulo->precio_unitario_sin_iva,
            'tasa_iva' => $articulo->objeto_imp === ObjetoImpuesto::SiObjeto ? TasaIva::Dieciseis->value : TasaIva::Exento->value,
        ])->values());
    }

    public function create(Request $request): View
    {
        return view('articulos.crear', $this->datosFormulario($request));
    }

    /**
     * El alta y su imagen van juntas: si la imagen no se puede guardar, no
     * queda el artículo a medias.
     */
    public function store(ArticuloRequest $request, ProcesadorImagenArticulo $imagenes): RedirectResponse
    {
        $articulo = DB::transaction(function () use ($request, $imagenes) {
            $articulo = $request->user()->articulos()->create($request->datosArticulo());
            $this->guardarImagen($request, $articulo, $imagenes);

            return $articulo;
        });

        return redirect()->route('articulos.index')->with('exito', 'Artículo creado. '.$this->precioGuardado($articulo).$this->avisoImagen($request));
    }

    public function edit(Request $request, Articulo $articulo): View
    {
        Gate::authorize('update', $articulo);

        return view('articulos.editar', $this->datosFormulario($request, $articulo));
    }

    /**
     * ArticuloRequest ya verificó que el artículo es del usuario.
     */
    public function update(ArticuloRequest $request, Articulo $articulo, ProcesadorImagenArticulo $imagenes): RedirectResponse
    {
        $articulo->update($request->datosArticulo());
        $this->guardarImagen($request, $articulo, $imagenes);

        return redirect()->route('articulos.index')->with('exito', 'Artículo actualizado. '.$this->precioGuardado($articulo).$this->avisoImagen($request));
    }

    /**
     * Una imagen nueva reemplaza a la actual aunque también se haya marcado
     * "Quitar imagen".
     */
    private function guardarImagen(ArticuloRequest $request, Articulo $articulo, ProcesadorImagenArticulo $imagenes): void
    {
        if ($request->hasFile('imagen')) {
            $imagenes->guardar($articulo, $request->file('imagen')->getContent());
        } elseif ($request->boolean('quitar_imagen')) {
            $imagenes->quitar($articulo);
        }
    }

    private function avisoImagen(ArticuloRequest $request): string
    {
        return match (true) {
            $request->hasFile('imagen') => ' Imagen actualizada.',
            $request->boolean('quitar_imagen') => ' Imagen quitada.',
            default => '',
        };
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
            ->withExists('existencia')
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
