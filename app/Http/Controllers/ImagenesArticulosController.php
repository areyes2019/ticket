<?php

namespace App\Http\Controllers;

use App\Http\Requests\CargarImagenesRequest;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Services\Articulos\ArchivoZipInvalido;
use App\Services\Articulos\CargadorImagenesArticulos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImagenesArticulosController extends Controller
{
    /**
     * Pantalla de carga masiva; muestra el reporte de la última carga.
     */
    public function create(Request $request): View
    {
        $catalogos = $request->user()->catalogos()->disponibles()->withCount('articulos')->get();

        return view('articulos.imagenes', [
            'catalogos' => $catalogos->pluck('etiqueta', 'id')->all(),
            'articulosPorCatalogo' => $catalogos->mapWithKeys(fn (Catalogo $catalogo) => [$catalogo->id => [
                'nombre' => $catalogo->etiqueta,
                'articulos' => $catalogo->articulos_count,
            ]])->all(),
            'reporte' => session('reporte'),
        ]);
    }

    /**
     * Procesa las imágenes o el .zip. Sin AJAX vuelve a la pantalla con el
     * reporte, para que recargar no suba los archivos otra vez; con AJAX (las
     * tandas de carga-imagenes.js) responde el reporte en JSON.
     */
    public function store(CargarImagenesRequest $request, CargadorImagenesArticulos $cargador): RedirectResponse|JsonResponse
    {
        $catalogo = $request->user()->catalogos()->findOrFail($request->integer('catalogo_id'));

        try {
            $reporte = $request->hasFile('archivo')
                ? $cargador->cargarZip($catalogo, $request->file('archivo')->getRealPath())
                : $cargador->cargarArchivos($catalogo, $request->file('archivos'));
        } catch (ArchivoZipInvalido $excepcion) {
            throw ValidationException::withMessages(['archivo' => $excepcion->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json($reporte);
        }

        return redirect()->route('articulos.imagenes')
            ->with('reporte', [...$reporte, 'catalogo' => $catalogo->etiqueta])
            ->withInput(['catalogo_id' => $catalogo->id]);
    }

    /**
     * La imagen, solo para el dueño. La URL lleva la versión del archivo, así
     * que el navegador puede guardarla una semana: un reemplazo cambia la URL.
     */
    public function show(Articulo $articulo): StreamedResponse
    {
        Gate::authorize('view', $articulo);

        $disco = Storage::disk('local');

        abort_if($articulo->imagen_ruta === null || ! $disco->exists($articulo->imagen_ruta), 404);

        return $disco->response($articulo->imagen_ruta, null, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }
}
