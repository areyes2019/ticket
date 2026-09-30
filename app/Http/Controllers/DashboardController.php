<?php

namespace App\Http\Controllers;

use App\Support\Demo\BandejaCorreoDemo;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(BandejaCorreoDemo $bandeja): View
    {
        return view('dashboard', [
            'carpetas' => $bandeja->carpetas(),
            'etiquetas' => $bandeja->etiquetas(),
            'correos' => $bandeja->correos(),
        ]);
    }
}
