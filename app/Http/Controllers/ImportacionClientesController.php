<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportarClientesRequest;
use App\Services\Articulos\ArchivoCsvInvalido;
use App\Services\Clientes\ImportadorClientesCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ImportacionClientesController extends Controller
{
    /**
     * Pantalla de importación; muestra el reporte de la última importación.
     */
    public function create(): View
    {
        return view('clientes.importar', [
            'reporte' => session('reporte'),
        ]);
    }

    /**
     * Procesa el CSV y vuelve a la pantalla con el reporte, para que recargar
     * la página no importe el archivo otra vez.
     */
    public function store(ImportarClientesRequest $request, ImportadorClientesCsv $importador): RedirectResponse
    {
        try {
            $reporte = $importador->importar($request->file('archivo')->getRealPath(), $request->user());
        } catch (ArchivoCsvInvalido $excepcion) {
            return redirect()->route('clientes.importar')->withErrors(['archivo' => $excepcion->getMessage()]);
        }

        return redirect()->route('clientes.importar')->with('reporte', $reporte);
    }
}
