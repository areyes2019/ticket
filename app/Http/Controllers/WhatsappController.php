<?php

namespace App\Http\Controllers;

use App\Enums\EstadoConversacion;
use App\Models\Cliente;
use App\Models\RespuestaRapida;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappPlantilla;
use App\Services\Whatsapp\EnviadorWhatsapp;
use App\Services\Whatsapp\EstadoConversaciones;
use App\Services\Whatsapp\MetaException;
use App\Services\Whatsapp\RegistroUso;
use App\Services\Whatsapp\Telefono;
use App\Services\Whatsapp\VentanaCerrada;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bandeja de WhatsApp (041, reglas 56 a 63).
 */
class WhatsappController extends Controller
{
    public const POR_PAGINA = 30;

    /**
     * Mensajes que se pintan al abrir una conversación; lo anterior se ve con
     * "Ver anteriores".
     */
    public const MENSAJES_VISIBLES = 150;

    public function index(Request $request): View
    {
        return $this->bandeja($request, null);
    }

    public function show(Request $request, WhatsappConversacion $conversacion): View
    {
        Gate::authorize('view', $conversacion);

        return $this->bandeja($request, $conversacion);
    }

    /**
     * Solo la lista, para el refresco de cada 20 segundos (regla 63).
     */
    public function lista(Request $request): View
    {
        $abierta = $request->integer('abierta') ?: null;

        return view('whatsapp._lista', [
            ...$this->datosLista($request),
            'abiertaId' => $abierta,
        ]);
    }

    /**
     * Lo nuevo de una conversación abierta desde el último mensaje pintado.
     */
    public function nuevos(Request $request, WhatsappConversacion $conversacion): JsonResponse
    {
        Gate::authorize('view', $conversacion);

        $mensajes = $conversacion->mensajes()->with('user')->where('id', '>', $request->integer('desde'))->get();

        // Los cambios de estado de envío (entregado, leído) de lo ya pintado.
        $estados = $conversacion->mensajes()
            ->where('direccion', WhatsappMensaje::SALIENTE)
            ->where('id', '<=', $request->integer('desde'))
            ->where('updated_at', '>=', now()->subMinutes(5))
            ->get()
            ->mapWithKeys(fn (WhatsappMensaje $m) => [$m->id => $m->estadoEnvioTexto()]);

        return response()->json([
            'html' => $mensajes->map(fn (WhatsappMensaje $m) => view('whatsapp._mensaje', ['mensaje' => $m])->render())->implode(''),
            'ultimo' => $mensajes->last()?->id ?? $request->integer('desde'),
            'estados' => $estados,
            'encabezado' => view('whatsapp._encabezado', ['conversacion' => $conversacion->fresh(['cliente', 'numero'])])->render(),
        ]);
    }

    public function enviarMensaje(Request $request, WhatsappConversacion $conversacion, EnviadorWhatsapp $enviador, EstadoConversaciones $estados): RedirectResponse
    {
        Gate::authorize('view', $conversacion);

        $datos = $request->validate([
            'texto' => ['nullable', 'required_without:archivo', 'string', 'max:4000'],
            'archivo' => ['nullable', 'file', 'max:'.(config('whatsapp.max_mb_archivo') * 1024), 'mimes:jpg,jpeg,png,webp,pdf'],
        ]);

        // Regla 65: el servidor valida la ventana aunque la pantalla la bloquee.
        if (! $conversacion->ventanaAbierta()) {
            return $this->volver($conversacion)->with('error', (new VentanaCerrada)->getMessage());
        }

        $estados->tomarPorUsuario($conversacion);

        try {
            if ($request->hasFile('archivo')) {
                $archivo = $request->file('archivo');
                $enviador->documento($conversacion, $archivo->get(), $archivo->getClientOriginalName(), $archivo->getMimeType(), 'usuario', $request->user(), $datos['texto'] ?? null);
            } else {
                $enviador->texto($conversacion, $datos['texto'], 'usuario', $request->user());
            }
        } catch (MetaException $error) {
            return $this->volver($conversacion)->with('error', $error->getMessage());
        }

        return $this->volver($conversacion);
    }

    public function enviarPlantilla(Request $request, WhatsappConversacion $conversacion, EnviadorWhatsapp $enviador, EstadoConversaciones $estados): RedirectResponse
    {
        Gate::authorize('view', $conversacion);

        [$plantilla, $variables] = $this->plantillaPedida($request);

        try {
            $enviador->plantilla($conversacion, $plantilla, $variables, $request->user());
        } catch (MetaException $error) {
            return $this->volver($conversacion)->with('error', $error->getMessage());
        }

        $estados->tomarPorUsuario($conversacion);

        return $this->volver($conversacion)->with('exito', 'Plantilla enviada.');
    }

    /**
     * Regla 60: "Escribir por WhatsApp" desde la ficha del cliente.
     */
    public function abrirCliente(Request $request, Cliente $cliente): View|RedirectResponse
    {
        Gate::authorize('update', $cliente);

        $numero = $request->user()->whatsappNumero;
        $diez = Telefono::diezDigitos($cliente->telefono);

        if ($numero === null) {
            return redirect()->route('clientes.edit', $cliente)->with('error', 'Primero conecta el número de WhatsApp en Configuración.');
        }

        if ($diez === null) {
            return redirect()->route('clientes.edit', $cliente)->with('error', 'El cliente no tiene un teléfono de 10 dígitos.');
        }

        $conversacion = $numero->conversaciones()->where('telefono_10', $diez)->first();

        if ($conversacion !== null) {
            return redirect()->route('whatsapp.show', $conversacion);
        }

        return view('whatsapp.nueva', [
            'cliente' => $cliente,
            'plantillas' => $numero->plantillas()->where('estado', 'APPROVED')->orderBy('nombre')->get(),
        ]);
    }

    /**
     * Regla 11: la conversación nace en manos de humano, con el agente pausado.
     */
    public function plantillaNueva(Request $request, Cliente $cliente, EnviadorWhatsapp $enviador): RedirectResponse
    {
        Gate::authorize('update', $cliente);

        $numero = $request->user()->whatsappNumero;
        $diez = Telefono::diezDigitos($cliente->telefono);
        abort_if($numero === null || $diez === null, 422);

        [$plantilla, $variables] = $this->plantillaPedida($request);

        $conversacion = $numero->conversaciones()->firstOrNew(['wa_id' => Telefono::waIdMexico($diez)]);
        $conversacion->forceFill([
            'user_id' => $numero->user_id,
            'telefono_10' => $diez,
            'cliente_id' => $cliente->id,
            'estado' => $conversacion->exists ? $conversacion->estado : EstadoConversacion::Humano,
            'motivo_humano' => $conversacion->motivo_humano ?? 'Conversación iniciada por un usuario',
        ])->save();

        try {
            $enviador->plantilla($conversacion, $plantilla, $variables, $request->user());
        } catch (MetaException $error) {
            return redirect()->route('whatsapp.show', $conversacion)->with('error', $error->getMessage());
        }

        return redirect()->route('whatsapp.show', $conversacion)->with('exito', 'Plantilla enviada.');
    }

    public function cambiarEstado(Request $request, WhatsappConversacion $conversacion, EstadoConversaciones $estados): RedirectResponse
    {
        Gate::authorize('view', $conversacion);

        $accion = $request->validate(['accion' => ['required', Rule::in(['pausar', 'reactivar', 'cerrar', 'reabrir'])]])['accion'];

        match ($accion) {
            'pausar' => $estados->pasarAHumano($conversacion, 'Pausado por '.$request->user()->name, avisar: false),
            'reactivar', 'reabrir' => $conversacion->estado === EstadoConversacion::Cerrada ? $estados->reabrir($conversacion) : $estados->reactivar($conversacion),
            'cerrar' => $estados->cerrar($conversacion),
        };

        return $this->volver($conversacion);
    }

    /**
     * Regla 71.
     */
    public function quitarLimite(WhatsappConversacion $conversacion): RedirectResponse
    {
        Gate::authorize('view', $conversacion);

        $conversacion->forceFill(['sin_limite' => true])->save();
        EnviadorWhatsapp::nota($conversacion, 'Límite de mensajes quitado hasta que se cierre la conversación.');

        return $this->volver($conversacion)->with('exito', 'Límite quitado.');
    }

    /**
     * Regla 77.
     */
    public function ligarCliente(Request $request, WhatsappConversacion $conversacion): RedirectResponse
    {
        Gate::authorize('view', $conversacion);

        $datos = $request->validate([
            'cliente_id' => ['nullable', 'integer', Rule::exists('clientes', 'id')->where('user_id', $request->user()->id)->withoutTrashed()],
        ]);

        $conversacion->forceFill(['cliente_id' => $datos['cliente_id'] ?? null])->save();

        return $this->volver($conversacion)->with('exito', 'Cliente actualizado.');
    }

    public function archivo(Request $request, WhatsappMensaje $mensaje): StreamedResponse
    {
        Gate::authorize('view', $mensaje->conversacion);
        abort_if($mensaje->archivo_ruta === null || ! Storage::disk('local')->exists($mensaje->archivo_ruta), 404);

        $nombre = $mensaje->archivo_nombre ?? basename($mensaje->archivo_ruta);
        $cabeceras = ['Content-Type' => $mensaje->archivo_mime ?? 'application/octet-stream'];

        return $request->boolean('descargar')
            ? Storage::disk('local')->download($mensaje->archivo_ruta, $nombre, $cabeceras)
            : Storage::disk('local')->response($mensaje->archivo_ruta, $nombre, $cabeceras);
    }

    private function bandeja(Request $request, ?WhatsappConversacion $abierta): View
    {
        $numero = $request->user()->whatsappNumero;
        $abierta?->load(['cliente', 'numero']);

        $mensajes = $abierta?->mensajes()->reorder()->with('user')->orderByDesc('id')->limit(self::MENSAJES_VISIBLES)->get()->reverse()->values();

        return view('whatsapp.index', [
            ...$this->datosLista($request),
            'numero' => $numero,
            'abierta' => $abierta,
            'abiertaId' => $abierta?->id,
            'mensajes' => $mensajes,
            'plantillas' => $numero?->plantillas()->where('estado', 'APPROVED')->orderBy('nombre')->get() ?? collect(),
            'clientes' => $abierta === null ? collect() : $request->user()->clientes()->orderBy('razon_social')->get(['id', 'razon_social', 'rfc', 'nombre_contacto']),
            'gasto' => $numero === null ? null : app(RegistroUso::class)->porcentaje($request->user(), $numero->tope_mensual_usd),
            'respuestasRapidas' => $this->respuestasRapidas($abierta),
        ]);
    }

    /**
     * Lo que necesita la lista de "/" en el chat (043, adición 1): todas las
     * respuestas rápidas y los valores de {nombre} y {fecha}. Solo con la
     * ventana de 24 horas abierta, porque sin ella no hay caja de respuesta
     * (regla 13).
     *
     * @return array{respuestas: list<array{atajo: string, texto: string}>, nombre: string, fecha: string}|null
     */
    private function respuestasRapidas(?WhatsappConversacion $abierta): ?array
    {
        if ($abierta === null || ! $abierta->ventanaAbierta()) {
            return null;
        }

        return [
            'respuestas' => RespuestaRapida::query()->ordenadas()->get(['atajo', 'texto'])
                ->map(fn (RespuestaRapida $r) => ['atajo' => $r->atajo, 'texto' => $r->texto])
                ->all(),
            // Regla 4: solo el cliente ligado, no el nombre de perfil de WhatsApp.
            'nombre' => (string) ($abierta->cliente?->nombre_contacto ?: $abierta->cliente?->razon_social ?: ''),
            'fecha' => now()->locale('es')->translatedFormat('j \d\e F \d\e Y'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function datosLista(Request $request): array
    {
        $carpeta = array_key_exists((string) $request->query('carpeta'), WhatsappConversacion::CARPETAS) ? (string) $request->query('carpeta') : 'todas';
        $texto = trim((string) $request->query('q'));
        $base = fn () => WhatsappConversacion::query()->where('user_id', $request->user()->id);

        $conversaciones = $base()
            ->enCarpeta($carpeta)
            ->buscar($texto)
            ->with(['cliente', 'mensajes' => fn ($q) => $q->reorder()->whereIn('direccion', ['entrante', 'saliente'])->latest('id')->limit(1)])
            // Regla 66: en "Requieren humano", primero las que están por vencer;
            // las de ventana ya cerrada, al final.
            ->when($carpeta === 'humano', fn ($q) => $q
                ->orderByRaw('CASE WHEN ultimo_mensaje_cliente_en IS NULL OR ultimo_mensaje_cliente_en < ? THEN 1 ELSE 0 END', [now()->subHours(WhatsappConversacion::HORAS_VENTANA)])
                ->orderBy('ultimo_mensaje_cliente_en'))
            ->orderByDesc('ultimo_mensaje_en')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        $contadores = [];

        foreach (array_keys(WhatsappConversacion::CARPETAS) as $clave) {
            $contadores[$clave] = $base()->enCarpeta($clave)->count();
        }

        return [
            'conversaciones' => $conversaciones,
            'carpeta' => $carpeta,
            'texto' => $texto,
            'contadores' => $contadores,
        ];
    }

    /**
     * @return array{0: WhatsappPlantilla, 1: list<string>}
     */
    private function plantillaPedida(Request $request): array
    {
        $numero = $request->user()->whatsappNumero;
        abort_if($numero === null, 404);

        $datos = $request->validate([
            'plantilla_id' => ['required', 'integer', Rule::exists('whatsapp_plantillas', 'id')->where('whatsapp_numero_id', $numero->id)],
            'variables' => ['array', 'max:10'],
            'variables.*' => ['required', 'string', 'max:500'],
        ]);

        $plantilla = WhatsappPlantilla::findOrFail($datos['plantilla_id']);
        $variables = array_values($datos['variables'] ?? []);

        if (count($variables) < $plantilla->variables) {
            throw new HttpResponseException(back()->withInput()->withErrors(['variables' => 'Llena todas las variables de la plantilla.']));
        }

        return [$plantilla, array_slice($variables, 0, $plantilla->variables)];
    }

    private function volver(WhatsappConversacion $conversacion): RedirectResponse
    {
        return redirect()->route('whatsapp.show', $conversacion);
    }
}
