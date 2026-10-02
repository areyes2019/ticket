<?php

namespace App\Http\Controllers;

use App\Services\Documentos\CalculadoraTotalesDocumento;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SaldoController extends Controller
{
    /**
     * Saldo actual de todas las cuentas, activas e inactivas, y el total
     * global. Es una lectura de cuentas: no suma movimientos.
     */
    public function __invoke(Request $request): View
    {
        $cuentas = $request->user()->cuentas()->orderBy('nombre')->get();

        return view('tesoreria.saldos', [
            'cuentas' => $cuentas,
            'total' => CalculadoraTotalesDocumento::pesos($cuentas->sum(fn ($cuenta) => CalculadoraTotalesDocumento::centavos($cuenta->saldo_actual))),
        ]);
    }
}
