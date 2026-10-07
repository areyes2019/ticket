<?php

namespace App\Http\Controllers;

use App\Http\Requests\FormatoEtiquetaRequest;
use App\Models\FormatoEtiqueta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Formatos de planilla de las etiquetas de producción (031). Todo regresa a
 * la página de etiquetas con el formato elegido.
 */
class FormatoEtiquetaController extends Controller
{
    public function store(FormatoEtiquetaRequest $request): RedirectResponse
    {
        $formato = new FormatoEtiqueta(['nombre' => $request->validated('nombre')]);
        $formato->user_id = $request->user()->id;

        return $this->guardar($request, $formato);
    }

    /**
     * FormatoEtiquetaRequest ya verificó que el formato es del usuario.
     */
    public function update(FormatoEtiquetaRequest $request, FormatoEtiqueta $formatoEtiqueta): RedirectResponse
    {
        $formatoEtiqueta->nombre = $request->validated('nombre');

        return $this->guardar($request, $formatoEtiqueta);
    }

    public function duplicar(FormatoEtiqueta $formatoEtiqueta): RedirectResponse
    {
        Gate::authorize('update', $formatoEtiqueta);

        return $this->volver($formatoEtiqueta->duplicar(), 'Formato duplicado.');
    }

    public function destroy(FormatoEtiqueta $formatoEtiqueta): RedirectResponse
    {
        Gate::authorize('delete', $formatoEtiqueta);

        $formatoEtiqueta->delete();

        return redirect()->route('pedidos.produccion.etiquetas')->with('exito', 'Formato eliminado.');
    }

    private function guardar(FormatoEtiquetaRequest $request, FormatoEtiqueta $formato): RedirectResponse
    {
        $formato->asignarMedidas($request->medidas());

        if ($request->validated('es_predeterminado')) {
            $formato->marcarPredeterminado();
        } else {
            $formato->es_predeterminado = false;
            $formato->save();
        }

        return $this->volver($formato, 'Formato guardado.');
    }

    private function volver(FormatoEtiqueta $formato, string $mensaje): RedirectResponse
    {
        return redirect()->route('pedidos.produccion.etiquetas', ['formato' => $formato->id])->with('exito', $mensaje);
    }
}
