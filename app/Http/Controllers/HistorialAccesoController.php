<?php

namespace App\Http\Controllers;

use App\Models\IntentoAcceso;
use Illuminate\View\View;

class HistorialAccesoController extends Controller
{
    /**
     * Muestra el historial de inicios de sesión (solo administradores).
     */
    public function index(): View
    {
        $intentos = IntentoAcceso::with('user')
            ->latest('id')
            ->paginate(25);

        return view('historial-accesos.index', ['intentos' => $intentos]);
    }
}
