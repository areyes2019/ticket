<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InicioController extends Controller
{
    public function index(): View
    {
        return view('inicio');
    }

    public function estado(): JsonResponse
    {
        return response()->json([
            'estado' => 'ok',
            'laravel' => app()->version(),
            'hora' => now()->toDateTimeString(),
        ]);
    }

    public function eco(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'mensaje' => ['required', 'string', 'max:255'],
        ]);

        return response()->json([
            'recibido' => $datos['mensaje'],
        ]);
    }
}
