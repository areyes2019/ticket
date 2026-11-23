<?php

namespace App\Http\Controllers;

use App\Models\WhatsappNumero;
use App\Models\WhatsappPlantilla;
use App\Services\Whatsapp\ClienteMeta;
use App\Services\Whatsapp\MetaException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sección "WhatsApp" de Configuración (041, reglas 4 a 7, 10 y 81). Solo el
 * administrador, y el número queda ligado a su cuenta.
 */
class ConfiguracionWhatsappController extends Controller
{
    private const ANCLA = '#whatsapp';

    public function update(Request $request): RedirectResponse
    {
        $numero = $request->user()->whatsappNumero ?? new WhatsappNumero;
        $nuevo = ! $numero->exists;

        $datos = $request->validate([
            'phone_number_id' => ['required', 'string', 'regex:/^\d{5,32}$/', Rule::unique('whatsapp_numeros', 'phone_number_id')->ignore($numero->id)],
            'waba_id' => ['required', 'string', 'regex:/^\d{5,32}$/'],
            // Los secretos solo se mandan para reemplazarlos (regla 5).
            'token' => [$nuevo ? 'required' : 'nullable', 'string', 'max:1000'],
            'app_secret' => [$nuevo ? 'required' : 'nullable', 'string', 'max:200'],
            'verify_token' => ['required', 'string', 'min:12', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'ventas_activo' => ['boolean'],
            'facturacion_activo' => ['boolean'],
            'instrucciones_ventas' => ['nullable', 'string', 'max:4000'],
            'instrucciones_facturacion' => ['nullable', 'string', 'max:4000'],
            'horario' => ['array'],
            'horario.*.abierto' => ['boolean'],
            'horario.*.abre' => ['nullable', 'date_format:H:i'],
            'horario.*.cierra' => ['nullable', 'date_format:H:i'],
            'aviso_humano' => ['nullable', 'string', 'max:500'],
            'aviso_humano_fuera_horario' => ['nullable', 'string', 'max:500'],
            'tope_mensual_usd' => ['required', 'numeric', 'min:0', 'max:100000'],
            'limite_hora' => ['required', 'integer', 'min:1', 'max:1000'],
            'limite_dia' => ['required', 'integer', 'min:1', 'max:10000'],
        ], [
            'phone_number_id.unique' => 'Este número ya está conectado a otra cuenta.',
            'phone_number_id.regex' => 'El identificador del número son solo dígitos.',
            'waba_id.regex' => 'El identificador de la cuenta de WhatsApp son solo dígitos.',
        ]);

        $horario = [];

        foreach (array_keys(WhatsappNumero::DIAS) as $dia) {
            $tramo = $datos['horario'][$dia] ?? [];
            $horario[$dia] = ! empty($tramo['abierto']) && ! empty($tramo['abre']) && ! empty($tramo['cierra']) && $tramo['abre'] < $tramo['cierra']
                ? [$tramo['abre'], $tramo['cierra']]
                : null;
        }

        $numero->fill([
            ...collect($datos)->except(['token', 'app_secret', 'horario'])->all(),
            'ventas_activo' => $request->boolean('ventas_activo'),
            'facturacion_activo' => $request->boolean('facturacion_activo'),
            'horario' => $horario,
        ]);

        foreach (['token', 'app_secret'] as $secreto) {
            if (filled($datos[$secreto] ?? null)) {
                $numero->{$secreto} = trim($datos[$secreto]);
            }
        }

        $numero->user_id = $request->user()->id;
        $numero->save();

        return $this->volver()->with('exito', 'Ajustes de WhatsApp guardados.');
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
