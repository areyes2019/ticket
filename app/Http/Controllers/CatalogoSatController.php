<?php

namespace App\Http\Controllers;

use App\Models\SatClaveProdServ;
use App\Models\SatClaveUnidad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sugerencias para los campos de claves SAT: los catálogos son demasiado
 * grandes para un select, así que se consultan mientras el usuario escribe.
 */
class CatalogoSatController extends Controller
{
    private const LIMITE = 20;

    public function clavesProdServ(Request $request): JsonResponse
    {
        return $this->buscar(SatClaveProdServ::query(), 'descripcion', $request);
    }

    public function clavesUnidad(Request $request): JsonResponse
    {
        return $this->buscar(SatClaveUnidad::query(), 'nombre', $request);
    }

    /**
     * Coincidencia por prefijo de la clave o parcial en el texto.
     *
     * @param  Builder<SatClaveProdServ>|Builder<SatClaveUnidad>  $consulta
     */
    private function buscar(Builder $consulta, string $columnaTexto, Request $request): JsonResponse
    {
        $termino = $request->string('q')->trim()->toString();

        if ($termino === '') {
            return response()->json([]);
        }

        $claves = $consulta
            ->where(fn (Builder $condicion) => $condicion
                ->where('clave', 'like', "{$termino}%")
                ->orWhere($columnaTexto, 'like', "%{$termino}%"))
            ->orderBy('clave')
            ->limit(self::LIMITE)
            ->get(['clave', "{$columnaTexto} as descripcion"]);

        return response()->json($claves);
    }
}
