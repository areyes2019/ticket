<?php

namespace App\Http\Controllers;

use App\Enums\ClaveConfiguracion;
use App\Http\Requests\ActualizarEmisorRequest;
use App\Http\Requests\ConfiguracionRequest;
use App\Models\Configuracion;
use App\Models\Emisor;
use App\Services\Pedidos\MensajePedido;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Almacén clave→valor por usuario (los dos mensajes de pedidos, 019) y los
 * datos del emisor de toda la instalación (026).
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
            'emisor' => Emisor::actual(),
        ]);
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

        return redirect()->route('configuracion.edit')->with('exito', 'Configuración guardada.');
    }
}
