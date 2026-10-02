<?php

namespace App\Http\Controllers;

use App\Enums\ClaveConfiguracion;
use App\Enums\ColorTinta;
use App\Enums\EstadoOrdenTrabajo;
use App\Http\Requests\OrdenTrabajoRequest;
use App\Models\OrdenTrabajo;
use App\Models\Pedido;
use App\Services\Imagenes\GuardadorImagenWebp;
use App\Services\Pedidos\MensajePedido;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * La orden de trabajo de una venta (022). Las rutas cuelgan del pedido: una
 * venta tiene una sola orden. Cliente, teléfono, folio y artículos se leen de
 * la venta; la orden guarda colores, imagen y estado.
 */
class OrdenTrabajoController extends Controller
{
    public function __construct(private readonly GuardadorImagenWebp $imagenes) {}

    public function create(Pedido $pedido): View|RedirectResponse
    {
        $respuesta = Gate::inspect('crearOrdenTrabajo', $pedido);

        if ($respuesta->status() === 404) {
            abort(404);
        }

        if ($respuesta->denied()) {
            return redirect()->route('pedidos.show', $pedido)->with('error', $respuesta->message());
        }

        return view('ordenes-trabajo.crear', $this->datosFormulario($pedido));
    }

    /**
     * OrdenTrabajoRequest ya revisó la regla; aquí se repite con la venta
     * bloqueada para que dos clics no creen dos órdenes. La imagen va dentro
     * de la transacción, como el alta de artículos (010): si no se guarda, no
     * queda la orden a medias.
     */
    public function store(OrdenTrabajoRequest $request, Pedido $pedido): RedirectResponse
    {
        DB::transaction(function () use ($request, $pedido) {
            $bloqueado = Pedido::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            $motivo = $bloqueado->motivoNoCreaOrdenTrabajo();

            if ($motivo !== null) {
                throw ValidationException::withMessages(['orden' => $motivo]);
            }

            $orden = new OrdenTrabajo;
            $orden->user_id = $bloqueado->user_id;
            $orden->pedido_id = $bloqueado->id;
            $orden->save();

            $orden->lineas()->createMany($request->colores());
            $this->guardarImagen($request, $orden);
        });

        return redirect()->route('pedidos.orden-trabajo.show', $pedido)
            ->with('exito', "Orden de trabajo de {$pedido->folio_formateado} creada.");
    }

    public function show(Pedido $pedido): View
    {
        Gate::authorize('verOrdenTrabajo', $pedido);

        return view('ordenes-trabajo.show', self::datosVistaPrevia($pedido));
    }

    /**
     * Fragmento HTML del visor del dashboard (spec 020, corrección 1).
     */
    public function vistaPrevia(Pedido $pedido): View
    {
        Gate::authorize('verOrdenTrabajo', $pedido);

        return view('ordenes-trabajo._vista-previa', self::datosVistaPrevia($pedido));
    }

    /**
     * Lo que pintan el detalle y la vista previa; también lo usa el dashboard
     * para la orden que abre con la página.
     *
     * @return array<string, mixed>
     */
    public static function datosVistaPrevia(Pedido $pedido): array
    {
        $orden = self::orden($pedido);

        return [
            'pedido' => $pedido,
            'orden' => $orden,
            'mensajeListo' => $orden->estado === EstadoOrdenTrabajo::Terminado
                ? app(MensajePedido::class)->resolver($pedido, ClaveConfiguracion::MensajeListo)
                : null,
        ];
    }

    public function edit(Pedido $pedido): View
    {
        Gate::authorize('editarOrdenTrabajo', $pedido);

        return view('ordenes-trabajo.editar', $this->datosFormulario($pedido, self::orden($pedido)));
    }

    /**
     * Reemplaza los colores de todas las líneas; una imagen nueva reemplaza
     * a la actual aunque también se haya marcado "Quitar imagen".
     */
    public function update(OrdenTrabajoRequest $request, Pedido $pedido): RedirectResponse
    {
        $orden = $pedido->ordenTrabajo;

        DB::transaction(function () use ($request, $orden) {
            $orden->lineas()->delete();
            $orden->lineas()->createMany($request->colores());
            $orden->touch();
            $this->guardarImagen($request, $orden);
        });

        return redirect()->route('pedidos.orden-trabajo.show', $pedido)
            ->with('exito', "Orden de trabajo de {$pedido->folio_formateado} actualizada.");
    }

    /**
     * Siguiente estado, sin regreso. La regla se revisa con la orden
     * bloqueada: dos clics no la saltan dos estados. Desde el visor del
     * dashboard (origen=dashboard) regresa ahí con la orden abierta.
     */
    public function avanzar(Request $request, Pedido $pedido): RedirectResponse
    {
        Gate::authorize('verOrdenTrabajo', $pedido);

        $resultado = DB::transaction(function () use ($pedido) {
            $orden = OrdenTrabajo::whereKey($pedido->ordenTrabajo->id)->lockForUpdate()->firstOrFail();
            $orden->load(['pedido.lineas', 'lineas']);
            $motivo = $orden->motivoNoAvanza();

            if ($motivo !== null) {
                return ['error', $motivo];
            }

            $orden->avanzar();
            $orden->save();

            return ['exito', "{$pedido->folio_formateado} pasó a {$orden->estado->etiqueta()}."];
        });

        $destino = $request->input('origen') === 'dashboard'
            ? route('dashboard', ['ot' => $pedido->ordenTrabajo->id])
            : route('pedidos.orden-trabajo.show', $pedido);

        return redirect($destino)->with(...$resultado);
    }

    /**
     * Hoja carta sin el layout de la aplicación; comparte la vista con la
     * hoja de producción.
     */
    public function imprimir(Pedido $pedido): View
    {
        Gate::authorize('verOrdenTrabajo', $pedido);

        return view('ordenes-trabajo.imprimir', [
            'titulo' => "Orden de trabajo {$pedido->folio_formateado}",
            'ordenes' => collect([self::orden($pedido)]),
            'esHojaProduccion' => false,
        ]);
    }

    public function imagen(Pedido $pedido): StreamedResponse
    {
        Gate::authorize('verOrdenTrabajo', $pedido);

        $ruta = $pedido->ordenTrabajo->imagen_ruta;
        $disco = Storage::disk('local');

        abort_if($ruta === null || ! $disco->exists($ruta), 404);

        // La URL lleva ?v={imagen_version}: un reemplazo cambia la dirección.
        return $disco->response($ruta, null, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    private function guardarImagen(OrdenTrabajoRequest $request, OrdenTrabajo $orden): void
    {
        if ($request->hasFile('imagen')) {
            $this->imagenes->guardar($orden, OrdenTrabajo::DIRECTORIO_IMAGENES, $request->file('imagen')->getContent());
        } elseif ($request->boolean('quitar_imagen')) {
            $this->imagenes->quitar($orden);
        }
    }

    /**
     * La orden con lo que pintan sus vistas: la venta y sus líneas.
     */
    private static function orden(Pedido $pedido): OrdenTrabajo
    {
        $orden = $pedido->ordenTrabajo;
        $orden->setRelation('pedido', $pedido->loadMissing('lineas'));
        $orden->load('lineas');

        return $orden;
    }

    /**
     * @return array<string, mixed>
     */
    private function datosFormulario(Pedido $pedido, ?OrdenTrabajo $orden = null): array
    {
        return [
            'pedido' => $pedido->loadMissing('lineas'),
            'orden' => $orden,
            'colores' => ColorTinta::opciones(),
        ];
    }
}
