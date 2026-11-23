<?php

namespace App\Http\Controllers;

use App\Enums\ClaveConfiguracion;
use App\Http\Requests\ActualizarEmisorRequest;
use App\Http\Requests\ConfiguracionRequest;
use App\Models\Configuracion;
use App\Models\DatoBancario;
use App\Models\Emisor;
use App\Services\Pedidos\MensajePedido;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Almacén clave→valor por usuario (los dos mensajes de pedidos, 019), los
 * datos del emisor de toda la instalación (026) y la lista de sus datos
 * bancarios (027; se editan en DatoBancarioController).
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
            'pestanas' => $this->pestanas($request, $emisor),
        ]);
    }

    /**
     * Las pestañas que ve este usuario, en orden (042, regla 2). Las tres del
     * negocio solo para el administrador; `advertencia` pinta la señal de las
     * reglas 17 y 18.
     *
     * @return list<array{ancla: string, titulo: string, icono: string, advertencia: ?string}>
     */
    private function pestanas(Request $request, Emisor $emisor): array
    {
        $pestanas = [];

        if (Gate::allows('editar-emisor')) {
            $pestanas[] = ['ancla' => 'emisor', 'titulo' => 'Emisor', 'icono' => 'building', 'advertencia' => $emisor->estaCompleto() ? null : 'Faltan datos fiscales'];
            $pestanas[] = ['ancla' => 'datos-bancarios', 'titulo' => 'Datos bancarios', 'icono' => 'bank', 'advertencia' => null];
            $pestanas[] = ['ancla' => 'whatsapp', 'titulo' => 'WhatsApp', 'icono' => 'whatsapp', 'advertencia' => $request->user()->whatsappNumero === null ? 'Sin conectar' : null];
        }

        $pestanas[] = ['ancla' => 'mensajes', 'titulo' => 'Mensajes de pedidos', 'icono' => 'envelope', 'advertencia' => null];

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
