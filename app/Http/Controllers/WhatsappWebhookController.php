<?php

namespace App\Http\Controllers;

use App\Models\WhatsappNumero;
use App\Services\Whatsapp\ReceptorWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * La puerta pública de Meta (041, reglas 12 a 17). Fuera de la sesión y sin
 * CSRF: la firma de Meta es la autorización.
 */
class WhatsappWebhookController extends Controller
{
    /**
     * Verificación inicial que hace Meta al configurar el webhook.
     */
    public function verificar(Request $request): Response
    {
        $token = (string) $request->query('hub_verify_token');

        if ($request->query('hub_mode') === 'subscribe' && $token !== '' && WhatsappNumero::where('verify_token', $token)->exists()) {
            return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
        }

        return response('', 403);
    }

    public function recibir(Request $request, ReceptorWebhook $receptor): Response
    {
        $cuerpo = $request->getContent();

        // Adición 1: antes de decodificar nada.
        if (! ReceptorWebhook::firmaValida($cuerpo, $request->header('X-Hub-Signature-256'))) {
            Log::warning('Aviso de WhatsApp con firma inválida.', ['ip' => $request->ip()]);

            return response('', 403);
        }

        $aviso = json_decode($cuerpo, true);

        if (is_array($aviso)) {
            $receptor->procesar($aviso);
        }

        return response('', 200);
    }
}
