<?php

namespace App\Http\Controllers;

use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;

class EstilosController extends Controller
{
    public function __invoke(): View
    {
        abort_unless(app()->isLocal(), 404);

        // Error de ejemplo para mostrar cómo se ve un campo con error.
        view()->share('errors', (new ViewErrorBag)->put('default', new MessageBag([
            'muestra_error' => 'Dato no válido.',
        ])));

        return view('estilos');
    }
}
