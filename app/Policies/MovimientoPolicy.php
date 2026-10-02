<?php

namespace App\Policies;

use App\Models\Movimiento;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Un movimiento ajeno responde 404. Uno automático solo se consulta: se
 * corrige desde su documento origen.
 */
class MovimientoPolicy
{
    public function update(User $user, Movimiento $movimiento): Response
    {
        $dueno = $this->esDueno($user, $movimiento);

        if ($dueno->denied()) {
            return $dueno;
        }

        return match (true) {
            $movimiento->esAutomatico() => $this->negarAutomatico($movimiento),
            $movimiento->esTransferencia() => Response::deny('Una transferencia no se edita: elimínala y vuelve a capturarla.'),
            default => Response::allow(),
        };
    }

    public function delete(User $user, Movimiento $movimiento): Response
    {
        $dueno = $this->esDueno($user, $movimiento);

        if ($dueno->denied()) {
            return $dueno;
        }

        return $movimiento->esAutomatico() ? $this->negarAutomatico($movimiento) : Response::allow();
    }

    private function negarAutomatico(Movimiento $movimiento): Response
    {
        $origen = $movimiento->documentoOrigen();

        return Response::deny($origen === null
            ? 'Este movimiento lo generó otro módulo: corrígelo desde ahí.'
            : "Este movimiento lo generó {$origen['etiqueta']}: corrígelo desde ahí.");
    }

    private function esDueno(User $user, Movimiento $movimiento): Response
    {
        return $movimiento->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
