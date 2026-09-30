<?php

namespace App\Http\Controllers;

use App\Http\Requests\ComplementoPagoRequest;
use App\Models\Factura;
use App\Services\Facturacion\ResultadoTimbrado;
use App\Services\Facturacion\TimbradorFacturas;
use Illuminate\Http\RedirectResponse;

class ComplementoPagoController extends Controller
{
    /**
     * Crea y timbra el complemento de pago de una factura PPD. Si el anterior
     * intento falló, el mismo botón lo reintenta con los datos nuevos.
     */
    public function store(ComplementoPagoRequest $request, Factura $factura, TimbradorFacturas $timbrador): RedirectResponse
    {
        if (! $factura->puedeRegistrarComplemento()) {
            return back()->with('error', 'Solo una factura timbrada con método PPD y sin complemento timbrado admite un complemento de pago.');
        }

        $resultado = $timbrador->timbrarComplemento($factura, $request->datos());
        $complemento = $factura->complementoPago()->first();
        $detalle = redirect()->route('facturas.show', $factura);

        return match ($resultado) {
            ResultadoTimbrado::Timbrada => $detalle->with('exito', "Complemento de pago timbrado. UUID {$complemento->uuid_fiscal}."),
            ResultadoTimbrado::ErrorDatos, ResultadoTimbrado::ErrorPac => $detalle->with('error', "No se pudo timbrar el complemento de pago: {$complemento->error_timbrado}"),
            ResultadoTimbrado::EnCurso => $detalle->with('error', 'Esta factura ya se está timbrando. Espera unos segundos y vuelve a abrirla.'),
            ResultadoTimbrado::SinCambio => $detalle->with('error', 'La factura ya tiene un complemento de pago timbrado.'),
        };
    }
}
