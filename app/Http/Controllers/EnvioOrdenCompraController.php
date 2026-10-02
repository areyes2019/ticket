<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RegresaABandeja;
use App\Http\Requests\EnviarOrdenCompraRequest;
use App\Mail\OrdenCompraMail;
use App\Models\OrdenCompra;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EnvioOrdenCompraController extends Controller
{
    use RegresaABandeja;

    /**
     * Envía el correo con el PDF adjunto (síncrono) y marca la orden como
     * enviada. Si el correo falla, el estado no cambia.
     */
    public function correo(EnviarOrdenCompraRequest $request, OrdenCompra $ordenCompra): RedirectResponse
    {
        $destinatarios = $request->validated('destinatarios');

        try {
            Mail::to($destinatarios)->send(new OrdenCompraMail($ordenCompra));
        } catch (Throwable $error) {
            Log::error('No se pudo enviar la orden de compra por correo.', ['orden_compra' => $ordenCompra->id, 'error' => $error->getMessage()]);

            return back()->withInput()->withErrors(['destinatarios' => 'No se pudo enviar el correo. Revisa la configuración del servidor de correo e intenta de nuevo.'], 'envio');
        }

        $ordenCompra->marcarEnviada();

        return redirect()->to($this->destinoOrdenCompra($request, $ordenCompra))
            ->with('exito', 'Orden de compra enviada a '.implode(', ', $destinatarios).'.');
    }

    /**
     * Después de compartir el PDF desde el aparato del usuario (WhatsApp). Se
     * llama por AJAX; responde el estado para actualizar la pantalla.
     */
    public function marcarEnviada(OrdenCompra $ordenCompra): JsonResponse
    {
        Gate::authorize('operar', $ordenCompra);

        $ordenCompra->marcarEnviada();

        return response()->json([
            'estado' => $ordenCompra->estado->value,
            'etiqueta' => $ordenCompra->estado->etiqueta(),
        ]);
    }
}
