<?php

namespace App\Http\Controllers;

use App\Http\Requests\EnviarCotizacionRequest;
use App\Mail\CotizacionMail;
use App\Models\Cotizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EnvioCotizacionController extends Controller
{
    /**
     * Envía el correo con el PDF adjunto (síncrono) y marca la cotización como
     * enviada. Si el correo falla, el estado no cambia. Enviada desde la
     * bandeja, regresa a ella con la misma cotización abierta.
     */
    public function correo(EnviarCotizacionRequest $request, Cotizacion $cotizacion): RedirectResponse
    {
        $destinatarios = $request->validated('destinatarios');

        try {
            Mail::to($destinatarios)->send(new CotizacionMail($cotizacion));
        } catch (Throwable $error) {
            Log::error('No se pudo enviar la cotización por correo.', ['cotizacion' => $cotizacion->id, 'error' => $error->getMessage()]);

            return back()->withInput()->withErrors(['destinatarios' => 'No se pudo enviar el correo. Revisa la configuración del servidor de correo e intenta de nuevo.'], 'envio');
        }

        $cotizacion->marcarEnviada();

        $destino = route('cotizaciones.show', $cotizacion);

        if ($request->input('origen') === 'bandeja') {
            // La página anterior conserva carpeta, etiqueta y búsqueda; solo se
            // acepta si es la bandeja misma.
            $anterior = url()->previous();
            $destino = str_starts_with($anterior, route('cotizaciones.index').'?')
                ? $anterior
                : route('cotizaciones.index', ['cotizacion' => $cotizacion->id]);
        }

        return redirect()->to($destino)
            ->with('exito', 'Cotización enviada a '.implode(', ', $destinatarios).'.');
    }

    /**
     * Después de compartir el PDF desde el aparato del usuario (WhatsApp). Se
     * llama por AJAX; responde el estado para actualizar la pantalla.
     */
    public function marcarEnviada(Cotizacion $cotizacion): JsonResponse
    {
        Gate::authorize('operar', $cotizacion);

        $cotizacion->marcarEnviada();

        return response()->json([
            'estado' => $cotizacion->estado->value,
            'etiqueta' => $cotizacion->estado->etiqueta(),
        ]);
    }
}
