<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait RegresaAMostrador
{
    /**
     * Las capturas del mostrador (033) envían a las rutas de siempre con
     * origen=mostrador: solo cambia a dónde se regresa al terminar bien.
     */
    protected function vieneDelMostrador(Request $request): bool
    {
        return $request->input('origen') === 'mostrador';
    }
}
