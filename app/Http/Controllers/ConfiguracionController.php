<?php

namespace App\Http\Controllers;

use App\Enums\ClaveConfiguracion;
use App\Http\Requests\ConfiguracionRequest;
use App\Models\Configuracion;
use App\Services\Pedidos\MensajePedido;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Almacén clave→valor por usuario. Por ahora, los dos mensajes de pedidos
 * (019).
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
        ]);
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
