<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListadoArticulosRequest;
use App\Models\Articulo;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportacionArticulosController extends Controller
{
    /**
     * CSV con los artículos que coinciden con los filtros del listado, en su
     * mismo orden y sin paginar. Mismas columnas que espera la importación: los
     * valores calculados no viajan, y la utilidad sale vacía si se hereda.
     */
    public function __invoke(ListadoArticulosRequest $request): StreamedResponse
    {
        $articulos = $request->user()->articulos()
            ->filtrar($request->filtros())
            ->ordenar($request->orden(), $request->direccion());

        return response()->streamDownload(function () use ($articulos) {
            $salida = fopen('php://output', 'w');

            // Con BOM, Excel en español muestra bien los acentos al abrir el archivo.
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, Articulo::COLUMNAS_CSV, ',', '"', '');

            foreach ($articulos->lazy() as $articulo) {
                fputcsv($salida, [
                    $articulo->nombre,
                    $articulo->modelo,
                    $articulo->clave_prod_serv,
                    $articulo->clave_unidad,
                    $articulo->objeto_imp->value,
                    $articulo->precio_proveedor,
                    $articulo->utilidad_porcentaje ?? '',
                ], ',', '"', '');
            }

            fclose($salida);
        }, 'articulos-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
