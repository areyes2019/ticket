<?php

namespace App\Policies;

use App\Enums\EstadoOrdenCompra;
use App\Models\OrdenCompra;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Una orden ajena responde 404 en todas las acciones. Las reglas de estado
 * viven en el modelo; aquí solo se convierten en una respuesta con motivo.
 */
class OrdenCompraPolicy
{
    public function view(User $user, OrdenCompra $ordenCompra): Response
    {
        return $this->esDueno($user, $ordenCompra);
    }

    public function update(User $user, OrdenCompra $ordenCompra): Response
    {
        $dueno = $this->esDueno($user, $ordenCompra);

        if ($dueno->denied()) {
            return $dueno;
        }

        return match ($ordenCompra->estado) {
            EstadoOrdenCompra::Pagada => Response::deny('Una orden pagada no se edita: cancela el pago primero.'),
            EstadoOrdenCompra::Recibida => Response::deny('Una orden recibida ya no se edita.'),
            default => Response::allow(),
        };
    }

    public function delete(User $user, OrdenCompra $ordenCompra): Response
    {
        $dueno = $this->esDueno($user, $ordenCompra);

        if ($dueno->denied()) {
            return $dueno;
        }

        return $ordenCompra->puedeEliminarse()
            ? Response::allow()
            : Response::deny('Solo se elimina una orden en borrador.');
    }

    /**
     * Enviar, compartir, pagar, cancelar el pago, recibir, duplicar y
     * descargar el PDF: las reglas de estado las revisa cada controlador.
     */
    public function operar(User $user, OrdenCompra $ordenCompra): Response
    {
        return $this->esDueno($user, $ordenCompra);
    }

    private function esDueno(User $user, OrdenCompra $ordenCompra): Response
    {
        return $ordenCompra->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
