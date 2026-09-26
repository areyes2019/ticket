<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportarArticulosRequest;
use App\Services\Articulos\ArchivoCsvInvalido;
use App\Services\Articulos\ImportadorArticulosCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ImportacionArticulosController extends Controller
{
    /**
     * Pantalla de importación; muestra el reporte de la última importación.
     */
    public function create(Request $request): View
    {
        return view('articulos.importar', [
            'catalogos' => $request->user()->catalogos()->disponibles()->get()->pluck('etiqueta', 'id')->all(),
            'reporte' => session('reporte'),
        ]);
    }

    /**
     * Procesa el CSV y vuelve a la pantalla con el reporte, para que recargar
     * la página no importe el archivo otra vez.
     */
    public function store(ImportarArticulosRequest $request, ImportadorArticulosCsv $importador): RedirectResponse
    {
        $catalogo = $request->user()->catalogos()->findOrFail($request->integer('catalogo_id'));

        try {
            $reporte = $importador->importar($request->file('archivo')->getRealPath(), $request->user(), $catalogo);
        } catch (ArchivoCsvInvalido $excepcion) {
            return back()->withErrors(['archivo' => $excepcion->getMessage()])->withInput();
        }

        return redirect()->route('articulos.importar')
            ->with('reporte', $reporte)
            ->withInput(['catalogo_id' => $catalogo->id]);
    }
}
