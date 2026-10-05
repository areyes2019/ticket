<?php

namespace App\Policies;

use App\Enums\EstadoPedido;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Un pedido ajeno responde 404 en todas las acciones. Las reglas de estado
 * viven en el modelo; aquí solo se convierten en una respuesta con motivo.
 */
class PedidoPolicy
{
    public function view(User $user, Pedido $pedido): Response
    {
        return $this->esDueno($user, $pedido);
    }

    public function update(User $user, Pedido $pedido): Response
    {
        $dueno = $this->esDueno($user, $pedido);

        if ($dueno->denied()) {
            return $dueno;
        }

        return match (true) {
            $pedido->esEditable() => Response::allow(),
            $pedido->cobraEnCotizacion() => Response::deny("Los artículos se corrigen en la cotización {$pedido->cotizacion->folio_formateado}."),
            $pedido->estado === EstadoPedido::Pagado => Response::deny('Un pedido pagado ya no se edita: el ticket ya salió con esas líneas.'),
            default => Response::deny('Un pedido entregado ya no se edita.'),
        };
    }

    public function delete(User $user, Pedido $pedido): Response
    {
        $dueno = $this->esDueno($user, $pedido);

        if ($dueno->denied()) {
            return $dueno;
        }

        return match (true) {
            $pedido->cobraEnCotizacion() && $pedido->tienePagos() => Response::deny("La cotización {$pedido->cotizacion->folio_formateado} tiene pagos registrados: elimínalos antes de borrar la venta."),
            $pedido->tienePagos() => Response::deny('El pedido tiene pagos registrados: elimínalos antes de borrarlo.'),
            ! $pedido->puedeEliminarse() => Response::deny('Un pedido '.mb_strtolower($pedido->estado->etiqueta()).' no se puede eliminar.'),
            default => Response::allow(),
        };
    }

    /**
     * Ticket, etiqueta, pagos, entrega y deshacer: la regla de estado de cada
     * acción la revisa su controlador.
     */
    public function operar(User $user, Pedido $pedido): Response
    {
        return $this->esDueno($user, $pedido);
    }

    /**
     * La orden de trabajo (022) vive dentro de la venta: sus reglas también.
     */
    public function crearOrdenTrabajo(User $user, Pedido $pedido): Response
    {
        $dueno = $this->esDueno($user, $pedido);

        if ($dueno->denied()) {
            return $dueno;
        }

        $motivo = $pedido->motivoNoCreaOrdenTrabajo();

        return $motivo === null ? Response::allow() : Response::deny($motivo);
    }

    /**
     * Sin orden responde 404, como un recurso que no existe.
     */
    public function verOrdenTrabajo(User $user, Pedido $pedido): Response
    {
        $dueno = $this->esDueno($user, $pedido);

        if ($dueno->denied() || $pedido->ordenTrabajo === null) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    public function editarOrdenTrabajo(User $user, Pedido $pedido): Response
    {
        $ver = $this->verOrdenTrabajo($user, $pedido);

        if ($ver->denied()) {
            return $ver;
        }

        return $pedido->ordenTrabajo->esEditable()
            ? Response::allow()
            : Response::deny('La venta ya se entregó: la orden queda solo para consulta.');
    }

    private function esDueno(User $user, Pedido $pedido): Response
    {
        return $pedido->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
