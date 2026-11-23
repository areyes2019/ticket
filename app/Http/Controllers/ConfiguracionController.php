<?php

namespace App\Http\Controllers;

use App\Enums\ClaveConfiguracion;
use App\Http\Requests\ActualizarEmisorRequest;
use App\Http\Requests\ConfiguracionRequest;
use App\Models\Configuracion;
use App\Models\DatoBancario;
use App\Models\Emisor;
use App\Models\RespuestaRapida;
use App\Services\Pedidos\MensajePedido;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Almacén clave→valor por usuario (los dos mensajes de pedidos, 019), los
 * datos del emisor de toda la instalación (026) y la lista de sus datos
 * bancarios (027; se editan en DatoBancarioController) y de las respuestas
 * rápidas del chat (043; se editan en RespuestaRapidaController).
 */
class ConfiguracionController extends Controller
{
    public function edit(Request $request): View
    {
        return view('configuracion.edit', [
            'claves' => ClaveConfiguracion::cases(),
            'valores' => collect(ClaveConfiguracion::cases())
                ->mapWithKeys(fn (ClaveConfiguracion $clave) => [$clave->value => Configuracion::valor($request->user(), $clave)])
                ->all(),
            'huecos' => MensajePedido::HUECOS,
            'ejemplo' => MensajePedido::ejemplo(),
            'emisor' => $emisor = Emisor::actual(),
            'datosBancarios' => DatoBancario::query()->ordenados()->get(),
            'respuestasRapidas' => RespuestaRapida::query()->ordenadas()->get(),
            'pestanas' => $this->pestanas($request, $emisor),
        ]);
    }

    /**
     * Las pestañas que ve este usuario, en orden (046, reglas 1, 14 y 15).
     * El usuario normal tiene Negocio (solo Mensajes de pedidos) y WhatsApp
     * (solo Respuestas rápidas); Agentes IA y las señales de advertencia
     * (regla 16) son del administrador.
     *
     * @return list<array{ancla: string, titulo: string, icono: string, advertencia: ?string}>
     */
    private function pestanas(Request $request, Emisor $emisor): array
    {
        $admin = Gate::allows('editar-emisor');

        $pestanas = [
            ['ancla' => 'negocio', 'titulo' => 'Negocio', 'icono' => 'shop', 'advertencia' => $admin && ! $emisor->estaCompleto() ? 'Faltan datos fiscales' : null],
            ['ancla' => 'whatsapp', 'titulo' => 'WhatsApp', 'icono' => 'whatsapp', 'advertencia' => $admin && $request->user()->whatsappNumero === null ? 'Sin conectar' : null],
        ];

        if ($admin) {
            $pestanas[] = ['ancla' => 'agentes', 'titulo' => 'Agentes IA', 'icono' => 'robot', 'advertencia' => null];
        }

        return $pestanas;
    }

    /**
     * El emisor de toda la instalación (026): crea la fila la primera vez y la
     * actualiza después; nunca inserta una segunda.
     */
    public function actualizarEmisor(ActualizarEmisorRequest $request): RedirectResponse
    {
        Emisor::actual()->fill($request->validated())->save();

        return redirect()->to(route('configuracion.edit').'#emisor')->with('exito', 'Datos del emisor guardados.');
    }

    /**
     * Horario de atención del negocio (046, adición 1). Un día cerrado, sin
     * horas o con apertura no menor que el cierre queda cerrado (null).
     */
    public function actualizarHorario(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'horario' => ['array'],
            'horario.*.abierto' => ['boolean'],
            'horario.*.abre' => ['nullable', 'date_format:H:i'],
            'horario.*.cierra' => ['nullable', 'date_format:H:i'],
        ]);

        $horario = [];

        foreach (array_keys(Emisor::DIAS) as $dia) {
            $tramo = $datos['horario'][$dia] ?? [];
            $horario[$dia] = ! empty($tramo['abierto']) && ! empty($tramo['abre']) && ! empty($tramo['cierra']) && $tramo['abre'] < $tramo['cierra']
                ? [$tramo['abre'], $tramo['cierra']]
                : null;
        }

        Emisor::actual()->fill(['horario' => $horario])->save();

        return redirect()->to(route('configuracion.edit').'#horario')->with('exito', 'Horario de atención guardado.');
    }

    public function update(ConfiguracionRequest $request): RedirectResponse
    {
        foreach (ClaveConfiguracion::cases() as $clave) {
            $request->user()->configuraciones()->updateOrCreate(
                ['clave' => $clave->value],
                ['valor' => $request->validated($clave->value)],
            );
        }

        return redirect()->to(route('configuracion.edit').'#mensajes')->with('exito', 'Configuración guardada.');
    }
}
