<?php

namespace App\Policies;

use App\Models\FormatoEtiqueta;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Un formato ajeno responde 404 en todas las acciones.
 */
class FormatoEtiquetaPolicy
{
    public function update(User $user, FormatoEtiqueta $formato): Response
    {
        return $this->esDueno($user, $formato);
    }

    public function delete(User $user, FormatoEtiqueta $formato): Response
    {
        return $this->esDueno($user, $formato);
    }

    private function esDueno(User $user, FormatoEtiqueta $formato): Response
    {
        return $formato->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
