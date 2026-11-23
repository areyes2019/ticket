<?php

namespace App\Http\Controllers;

use App\Http\Requests\RespuestaRapidaRequest;
use App\Models\RespuestaRapida;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Respuestas rápidas del chat de WhatsApp (043). La lista vive en la pestaña
 * de Configuración; aquí el formulario y las acciones. Del negocio y para
 * cualquier usuario con sesión (regla 19), sin scope por usuario.
 */
class RespuestaRapidaController extends Controller
{
    public function create(): View
    {
        return view('configuracion.respuestas-rapidas.crear');
    }

    public function store(RespuestaRapidaRequest $request): RedirectResponse
    {
        RespuestaRapida::create($request->validated());

        return $this->volver('Respuesta rápida guardada.');
    }

    public function edit(RespuestaRapida $respuestaRapida): View
    {
        return view('configuracion.respuestas-rapidas.editar', ['respuesta' => $respuestaRapida]);
    }

    public function update(RespuestaRapidaRequest $request, RespuestaRapida $respuestaRapida): RedirectResponse
    {
        $respuestaRapida->update($request->validated());

        return $this->volver('Respuesta rápida guardada.');
    }

    public function destroy(RespuestaRapida $respuestaRapida): RedirectResponse
    {
        $respuestaRapida->delete();

        return $this->volver('Respuesta rápida eliminada.');
    }

    private function volver(string $mensaje): RedirectResponse
    {
        return redirect()->to(route('configuracion.edit').'#respuestas-rapidas')->with('exito', $mensaje);
    }
}
