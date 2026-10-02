<?php

namespace App\Http\Controllers;

use App\Exceptions\CuentaInactivaException;
use App\Exceptions\OperacionTesoreriaRechazada;
use App\Http\Requests\TransferenciaRequest;
use App\Services\Tesoreria\RegistradorMovimientos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class TransferenciaController extends Controller
{
    /**
     * Una operación para el usuario; dos movimientos vinculados en la base.
     */
    public function store(TransferenciaRequest $request, RegistradorMovimientos $registrador): RedirectResponse
    {
        $cuentas = $request->user()->cuentas();
        $origen = (clone $cuentas)->findOrFail($request->validated('cuenta_origen_id'));
        $destino = (clone $cuentas)->findOrFail($request->validated('cuenta_destino_id'));

        try {
            $registrador->transferir($origen, $destino, $request->validated('monto'), $request->validated('fecha'), $request->validated('concepto'));
        } catch (OperacionTesoreriaRechazada $rechazo) {
            $campo = match (true) {
                ! $rechazo instanceof CuentaInactivaException => 'monto',
                $rechazo->cuenta->is($origen) => 'cuenta_origen_id',
                default => 'cuenta_destino_id',
            };

            throw ValidationException::withMessages([$campo => $rechazo->getMessage()])->errorBag('transferencia');
        }

        return back()->with('exito', 'Transferencia de $'.number_format((float) $request->validated('monto'), 2)." de {$origen->nombre} a {$destino->nombre} registrada.");
    }
}
