<?php

namespace App\Http\Controllers;

use App\Http\Requests\MedidasPlanillaRequest;
use App\Models\FormatoEtiqueta;
use App\Services\Etiquetas\MedidasPlanilla;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Etiquetas de producción (030): una etiqueta por cada orden "En proceso",
 * repartidas en hojas carta según las medidas de la planilla (031).
 *
 * Las medidas salen, en este orden, de la dirección, del formato indicado, del
 * predeterminado o de fábrica. inicio (1 a por_hoja) deja en blanco las
 * casillas ya usadas de la primera hoja; prueba=1 pinta solo las casillas
 * numeradas, para empalmarlas con la planilla.
 */
class EtiquetasProduccionController extends Controller
{
    public function index(Request $request): View
    {
        $formatos = $request->user()->formatosEtiqueta()->get();
        $formato = $this->formatoElegido($request, $formatos);
        $medidas = MedidasPlanilla::desdePeticion($request->query(), $formato?->medidas() ?? MedidasPlanilla::fabrica());
        $porHoja = $medidas->porHoja();
        $prueba = $request->boolean('prueba');

        $inicio = filter_var($request->query('inicio'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => max(1, $porHoja), 'default' => 1],
        ]);

        $ordenes = $prueba ? collect() : $request->user()->ordenesTrabajo()
            ->enProduccion()
            ->with(['pedido.lineas.articulo.catalogo', 'pedido.pagos', 'pedido.cotizacion.pagos'])
            ->get();

        return view('ordenes-trabajo.etiquetas', [
            'ordenes' => $ordenes,
            'formatos' => $formatos,
            'formato' => $formato,
            'medidas' => $medidas,
            'inicio' => $inicio,
            'prueba' => $prueba,
            'hojas' => match (true) {
                ! $medidas->cabe() => collect(),
                $prueba => collect([range(1, $porHoja)]),
                default => collect(array_fill(0, $inicio - 1, null))->concat($ordenes)->chunk($porHoja),
            },
        ]);
    }

    /**
     * El formulario de medidas sin JavaScript: valida y vuelve al GET con las
     * medidas en la dirección (centrar=1 llena antes los márgenes).
     */
    public function aplicar(MedidasPlanillaRequest $request): RedirectResponse
    {
        $medidas = $request->medidas();

        if ($request->boolean('centrar')) {
            $medidas = $medidas->centrada();
        }

        return redirect()->route('pedidos.produccion.etiquetas', array_filter([
            'formato' => $request->validated('formato'),
            ...$medidas->toArray(),
            'columnas' => $medidas->columnasParaDireccion(),
            'inicio' => $request->validated('inicio'),
            'prueba' => $request->boolean('prueba') ? 1 : null,
        ], fn ($valor) => $valor !== null && $valor !== ''));
    }

    /**
     * El formato de la dirección si es del usuario ("fabrica": ninguno); si no
     * viene o es ajeno, el predeterminado.
     *
     * @param  Collection<int, FormatoEtiqueta>  $formatos
     */
    private function formatoElegido(Request $request, Collection $formatos): ?FormatoEtiqueta
    {
        $pedido = $request->query('formato');

        if ($pedido === 'fabrica') {
            return null;
        }

        return $formatos->firstWhere('id', (int) $pedido) ?? $formatos->firstWhere('es_predeterminado', true);
    }
}
