<?php

namespace App\Http\Controllers;

use App\Http\Requests\DatoBancarioRequest;
use App\Models\DatoBancario;
use App\Services\Imagenes\GuardadorImagenWebp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Datos bancarios del negocio (027). La lista vive en Configuración; aquí
 * solo el formulario y las acciones, todas del administrador (la ruta lleva
 * can:editar-emisor) y sin scope por usuario, como el emisor.
 */
class DatoBancarioController extends Controller
{
    public function create(): View
    {
        return view('configuracion.datos-bancarios.crear');
    }

    /**
     * El banco y su logo van juntos: si el logo no se puede guardar, el banco
     * no se crea.
     */
    public function store(DatoBancarioRequest $request, GuardadorImagenWebp $imagenes): RedirectResponse
    {
        $dato = DB::transaction(function () use ($request, $imagenes) {
            $dato = DatoBancario::create($request->datos());
            $this->guardarLogo($request, $dato, $imagenes);

            return $dato;
        });

        return $this->volver("Banco {$dato->nombre_banco} agregado.");
    }

    public function edit(DatoBancario $datoBancario): View
    {
        return view('configuracion.datos-bancarios.editar', ['dato' => $datoBancario]);
    }

    public function update(DatoBancarioRequest $request, DatoBancario $datoBancario, GuardadorImagenWebp $imagenes): RedirectResponse
    {
        $datoBancario->update($request->datos());
        $this->guardarLogo($request, $datoBancario, $imagenes);

        return $this->volver("Banco {$datoBancario->nombre_banco} actualizado.");
    }

    /**
     * El archivo del logo se conserva a propósito: las cotizaciones ya creadas
     * lo tienen en su foto y lo siguen imprimiendo.
     */
    public function destroy(DatoBancario $datoBancario): RedirectResponse
    {
        $datoBancario->delete();

        return $this->volver("Banco {$datoBancario->nombre_banco} eliminado.");
    }

    public function alternarVisible(DatoBancario $datoBancario): RedirectResponse
    {
        $datoBancario->update(['visible_en_cotizaciones' => ! $datoBancario->visible_en_cotizaciones]);

        return $this->volver($datoBancario->visible_en_cotizaciones
            ? "{$datoBancario->nombre_banco} se mostrará en las cotizaciones nuevas."
            : "{$datoBancario->nombre_banco} ya no se mostrará en las cotizaciones nuevas.");
    }

    /**
     * Intercambia la posición con el vecino inmediato (en el extremo no hace
     * nada) y renumera la lista, para que nunca queden huecos ni repetidos.
     */
    public function mover(DatoBancario $datoBancario, string $direccion): RedirectResponse
    {
        DB::transaction(function () use ($datoBancario, $direccion) {
            $ids = DatoBancario::query()->ordenados()->lockForUpdate()->pluck('id')->all();
            $posicion = array_search($datoBancario->id, $ids, true);
            $destino = $direccion === 'arriba' ? $posicion - 1 : $posicion + 1;

            if (! isset($ids[$destino])) {
                return;
            }

            [$ids[$posicion], $ids[$destino]] = [$ids[$destino], $ids[$posicion]];

            foreach ($ids as $indice => $id) {
                DatoBancario::query()->whereKey($id)->update(['orden' => $indice + 1]);
            }
        });

        return redirect()->to(route('configuracion.edit').'#datos-bancarios');
    }

    /**
     * Los logos viven en el disco privado: esta es la única forma de verlos.
     */
    public function logo(DatoBancario $datoBancario): StreamedResponse
    {
        $disco = Storage::disk('local');

        abort_if($datoBancario->logo_ruta === null || ! $disco->exists($datoBancario->logo_ruta), 404);

        return $disco->response($datoBancario->logo_ruta, null, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    /**
     * Un logo nuevo reemplaza al actual (y borra su archivo) aunque también se
     * haya marcado "Quitar logo".
     */
    private function guardarLogo(DatoBancarioRequest $request, DatoBancario $dato, GuardadorImagenWebp $imagenes): void
    {
        if ($request->hasFile('logo')) {
            $imagenes->guardar($dato, DatoBancario::DIRECTORIO_LOGOS, $request->file('logo')->getContent(), DatoBancario::LADO_LOGO, 'logo_ruta');
        } elseif ($request->boolean('quitar_logo')) {
            $imagenes->quitar($dato, 'logo_ruta');
        }
    }

    private function volver(string $mensaje): RedirectResponse
    {
        return redirect()->to(route('configuracion.edit').'#datos-bancarios')->with('exito', $mensaje);
    }
}
