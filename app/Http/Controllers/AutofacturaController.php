<?php

namespace App\Http\Controllers;

use App\Enums\EstadoFactura;
use App\Enums\RegimenFiscal;
use App\Enums\UsoCfdi;
use App\Http\Requests\AutofacturaRequest;
use App\Models\Pedido;
use App\Services\Facturacion\FacturapiCliente;
use App\Services\Facturacion\FacturapiException;
use App\Services\Facturacion\GeneradorPdfFactura;
use App\Services\Facturacion\ResultadoTimbrado;
use App\Services\Pedidos\Autofacturador;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as RespuestaBase;

/**
 * Portal público de autofacturación (019). Sin sesión: el token de 64
 * caracteres del enlace es la autorización. Lo ve el cliente, no el usuario.
 */
class AutofacturaController extends Controller
{
    /**
     * Datos del pedido y formulario, el motivo por el que el enlace ya no
     * sirve, o el acuse con las descargas (?descargar=pdf|xml).
     */
    public function show(Request $request, string $token, GeneradorPdfFactura $pdf, FacturapiCliente $facturapi): RespuestaBase
    {
        $pedido = Pedido::where('autofactura_token', $token)->first();

        if ($pedido === null) {
            return response()->view('autofactura.show', ['pedido' => null], 404);
        }

        $factura = $pedido->facturaTimbrada();
        $timbrada = $factura?->estado === EstadoFactura::Timbrada ? $factura : null;

        if ($timbrada !== null && $request->query('descargar') === 'pdf') {
            return $pdf->generar($timbrada)->download($timbrada->nombreArchivo('pdf'));
        }

        if ($timbrada !== null && $request->query('descargar') === 'xml') {
            try {
                $xml = $facturapi->descargarXml($timbrada->facturapi_invoice_id, $timbrada->id);
            } catch (FacturapiException) {
                return redirect()->route('autofactura.show', $token)->with('error', 'No se pudo descargar el XML en este momento. Intenta de nuevo en unos minutos.');
            }

            return new Response($xml, 200, [
                'Content-Type' => 'application/xml',
                'Content-Disposition' => 'attachment; filename="'.$timbrada->nombreArchivo('xml').'"',
            ]);
        }

        return response()->view('autofactura.show', [
            'pedido' => $pedido,
            'token' => $token,
            'facturaTimbrada' => $timbrada,
            'motivo' => $pedido->motivoAutofacturaNoDisponible(),
            'regimenes' => RegimenFiscal::opciones(),
            'usosCfdi' => UsoCfdi::opcionesFactura(),
        ]);
    }

    public function store(AutofacturaRequest $request, string $token, Autofacturador $autofacturador): RedirectResponse
    {
        $pedido = Pedido::where('autofactura_token', $token)->firstOrFail();
        $resultado = $autofacturador->facturar($pedido, $request->datos());
        $volver = redirect()->route('autofactura.show', $token);

        if ($resultado->motivo !== null) {
            return $volver->with('error', $resultado->motivo);
        }

        return match ($resultado->timbrado) {
            ResultadoTimbrado::Timbrada => $volver->with('exito', $resultado->correoEnviado
                ? "Tu factura {$resultado->factura->folioFiscal()} quedó timbrada y la enviamos a {$request->datos()['correo']}."
                : "Tu factura {$resultado->factura->folioFiscal()} quedó timbrada. No pudimos enviarla por correo: descárgala aquí."),
            ResultadoTimbrado::ErrorDatos => $volver->withInput()->with('error', 'No se pudo timbrar tu factura: '.$resultado->factura->error_timbrado.' Revisa tus datos e inténtalo de nuevo.'),
            ResultadoTimbrado::ErrorPac => $volver->withInput()->with('error', 'El servicio de facturación no responde. Intenta de nuevo en unos minutos.'),
            ResultadoTimbrado::EnCurso => $volver->with('error', 'Tu factura se está procesando. Recarga la página en un momento.'),
            default => $volver,
        };
    }
}
