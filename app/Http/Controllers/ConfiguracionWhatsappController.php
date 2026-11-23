<?php

namespace App\Http\Controllers;

use App\Enums\AgenteWhatsapp;
use App\Models\WhatsappNumero;
use App\Models\WhatsappPlantilla;
use App\Services\Whatsapp\ClienteMeta;
use App\Services\Whatsapp\MetaException;
use App\Services\Whatsapp\TextoIndicaciones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pestañas "WhatsApp" (la conexión) y "Agentes IA" de Configuración (041,
 * reglas 4 a 7, 10 y 81; 046). Solo el administrador, y el número queda ligado
 * a su cuenta.
 */
class ConfiguracionWhatsappController extends Controller
{
    private const ANCLA = '#whatsapp';

    public const SIN_NUMERO = 'Primero conecta WhatsApp: llena la conexión con Meta y guarda.';

    /**
     * Guardar conexión (046, regla 9): solo los datos de Meta. Un número nuevo
     * toma los valores por defecto de agentes y límites (adición 2).
     */
    public function update(Request $request): RedirectResponse
    {
        $numero = $request->user()->whatsappNumero ?? new WhatsappNumero;
        $nuevo = ! $numero->exists;

        $datos = $request->validate([
            'phone_number_id' => ['required', 'string', 'regex:/^\d{5,32}$/', Rule::unique('whatsapp_numeros', 'phone_number_id')->ignore($numero->id)],
            'waba_id' => ['required', 'string', 'regex:/^\d{5,32}$/'],
            // Los secretos solo se mandan para reemplazarlos (041, regla 5).
            'token' => [$nuevo ? 'required' : 'nullable', 'string', 'max:1000'],
            'app_secret' => [$nuevo ? 'required' : 'nullable', 'string', 'max:200'],
            'verify_token' => ['required', 'string', 'min:12', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
        ], [
            'phone_number_id.unique' => 'Este número ya está conectado a otra cuenta.',
            'phone_number_id.regex' => 'El identificador del número son solo dígitos.',
            'waba_id.regex' => 'El identificador de la cuenta de WhatsApp son solo dígitos.',
        ]);

        $numero->fill(collect($datos)->only(['phone_number_id', 'waba_id', 'verify_token'])->all());

        foreach (['token', 'app_secret'] as $secreto) {
            if (filled($datos[$secreto] ?? null)) {
                $numero->{$secreto} = trim($datos[$secreto]);
            }
        }

        $numero->user_id = $request->user()->id;
        $numero->save();

        return $this->volver()->with('exito', 'Conexión con Meta guardada.');
    }

    /**
     * Guardar agentes (046, reglas 12 y 13): encendidos, indicaciones, avisos y
     * límites. Sin número no hay dónde guardarlos.
     */
    public function agentes(Request $request): RedirectResponse
    {
        $numero = $request->user()->whatsappNumero;

        if ($numero === null) {
            return $this->volver()->with('error', self::SIN_NUMERO);
        }

        // Pares de preguntas frecuentes vacíos fuera y renumerados (045, regla 32).
        foreach (AgenteWhatsapp::cases() as $agente) {
            $columna = $agente->columnaIndicaciones();

            if (is_array($request->input($columna))) {
                $request->merge([$columna => [
                    ...$request->input($columna),
                    'preguntas' => TextoIndicaciones::sinVacios($request->input($columna.'.preguntas')),
                ]]);
            }
        }

        $datos = $request->validate([
            'ventas_activo' => ['boolean'],
            'facturacion_activo' => ['boolean'],
            ...$this->reglasIndicaciones(),
            'aviso_humano' => ['nullable', 'string', 'max:500'],
            'aviso_humano_fuera_horario' => ['nullable', 'string', 'max:500'],
            'tope_mensual_usd' => ['required', 'numeric', 'min:0', 'max:100000'],
            'limite_hora' => ['required', 'integer', 'min:1', 'max:1000'],
            'limite_dia' => ['required', 'integer', 'min:1', 'max:10000'],
        ], $this->mensajesIndicaciones());

        // Solo la caja de cada agente con sus claves y los textos limpios (045, reglas 22 y 37).
        foreach (AgenteWhatsapp::cases() as $agente) {
            $columna = $agente->columnaIndicaciones();

            if (array_key_exists($columna, $datos)) {
                $datos[$columna] = TextoIndicaciones::normalizar($agente, $datos[$columna]);
            }
        }

        $numero->fill([
            ...$datos,
            'ventas_activo' => $request->boolean('ventas_activo'),
            'facturacion_activo' => $request->boolean('facturacion_activo'),
        ])->save();

        return redirect()->to(route('configuracion.edit').'#agentes')->with('exito', 'Ajustes de los agentes guardados.');
    }

    /**
     * Validación de las indicaciones por secciones (045, regla 17).
     *
     * @return array<string, list<string>>
     */
    private function reglasIndicaciones(): array
    {
        $reglas = [];

        foreach (AgenteWhatsapp::cases() as $agente) {
            $a = $agente->columnaIndicaciones();
            $reglas[$a] = ['nullable', 'array'];

            foreach (array_keys($agente->seccionesTexto()) as $clave) {
                $reglas["{$a}.{$clave}"] = ['nullable', 'string', 'max:'.TextoIndicaciones::MAX_SECCION];
            }

            $reglas["{$a}.preguntas"] = ['array', 'max:'.TextoIndicaciones::MAX_PREGUNTAS];
            $reglas["{$a}.preguntas.*.pregunta"] = ["required_with:{$a}.preguntas.*.respuesta", 'nullable', 'string', 'max:'.TextoIndicaciones::MAX_PREGUNTA];
            $reglas["{$a}.preguntas.*.respuesta"] = ["required_with:{$a}.preguntas.*.pregunta", 'nullable', 'string', 'max:'.TextoIndicaciones::MAX_RESPUESTA];
        }

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    private function mensajesIndicaciones(): array
    {
        $mensajes = [];

        foreach (AgenteWhatsapp::cases() as $agente) {
            $a = $agente->columnaIndicaciones();

            foreach ($agente->seccionesTexto() as $clave => $seccion) {
                $mensajes["{$a}.{$clave}.max"] = "{$seccion['titulo']}: máximo ".number_format(TextoIndicaciones::MAX_SECCION).' caracteres.';
            }

            $mensajes["{$a}.preguntas.max"] = 'Llegaste al máximo de '.TextoIndicaciones::MAX_PREGUNTAS.' preguntas frecuentes.';
            $mensajes["{$a}.preguntas.*.pregunta.required_with"] = 'Falta la pregunta.';
            $mensajes["{$a}.preguntas.*.respuesta.required_with"] = 'Falta la respuesta.';
            $mensajes["{$a}.preguntas.*.pregunta.max"] = 'La pregunta lleva máximo '.TextoIndicaciones::MAX_PREGUNTA.' caracteres.';
            $mensajes["{$a}.preguntas.*.respuesta.max"] = 'La respuesta lleva máximo '.TextoIndicaciones::MAX_RESPUESTA.' caracteres.';
        }

        return $mensajes;
    }

    public function probar(Request $request, ClienteMeta $meta): RedirectResponse
    {
        $numero = $this->numero($request);

        try {
            $datos = $meta->consultarNumero($numero);
        } catch (MetaException $error) {
            return $this->volver()->with('error', $error->getMessage());
        }

        $numero->forceFill([
            'numero_visible' => $datos['display_phone_number'] ?? $numero->numero_visible,
            'nombre_visible' => $datos['verified_name'] ?? $numero->nombre_visible,
        ])->save();

        return $this->volver()->with('exito', sprintf(
            'Conexión correcta: %s (%s). Calidad: %s. Nombre: %s.',
            $datos['verified_name'] ?? 'sin nombre',
            $datos['display_phone_number'] ?? 'sin número',
            $datos['quality_rating'] ?? 'sin dato',
            $datos['name_status'] ?? 'sin dato',
        ));
    }

    public function registrar(Request $request, ClienteMeta $meta): RedirectResponse
    {
        $numero = $this->numero($request);
        $pin = $request->validate(['pin' => ['required', 'digits:6']])['pin'];

        try {
            $meta->registrar($numero, $pin);
        } catch (MetaException $error) {
            return $this->volver()->with('error', $error->getMessage());
        }

        return $this->volver()->with('exito', 'Número registrado en la API de WhatsApp.');
    }

    public function plantillas(Request $request, ClienteMeta $meta): RedirectResponse
    {
        $numero = $this->numero($request);

        try {
            $plantillas = $meta->plantillas($numero);
        } catch (MetaException $error) {
            return $this->volver()->with('error', $error->getMessage());
        }

        $vigentes = [];

        foreach ($plantillas as $plantilla) {
            $guardada = $numero->plantillas()->updateOrCreate(
                ['nombre' => $plantilla['nombre'], 'idioma' => $plantilla['idioma']],
                [
                    'categoria' => $plantilla['categoria'],
                    'cuerpo' => $plantilla['cuerpo'],
                    'variables' => WhatsappPlantilla::contarVariables($plantilla['cuerpo']),
                    'estado' => $plantilla['estado'],
                ],
            );
            $vigentes[] = $guardada->id;
        }

        $numero->plantillas()->whereNotIn('id', $vigentes)->delete();
        $aprobadas = collect($plantillas)->where('estado', 'APPROVED')->count();

        return $this->volver()->with('exito', "Plantillas actualizadas: {$aprobadas} aprobadas de ".count($plantillas).'.');
    }

    private function numero(Request $request): WhatsappNumero
    {
        return $request->user()->whatsappNumero ?? abort(404);
    }

    private function volver(): RedirectResponse
    {
        return redirect()->to(route('configuracion.edit').self::ANCLA);
    }
}
