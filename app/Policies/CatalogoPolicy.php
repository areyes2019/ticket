<?php

namespace App\Policies;

use App\Models\Catalogo;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CatalogoPolicy
{
    /**
     * Solo el dueño puede editar el catálogo; a los demás se les responde 404.
     */
    public function update(User $user, Catalogo $catalogo): Response
    {
        return $this->esDueno($user, $catalogo);
    }

    /**
     * Solo el dueño puede eliminar el catálogo; a los demás se les responde 404.
     */
    public function delete(User $user, Catalogo $catalogo): Response
    {
        return $this->esDueno($user, $catalogo);
    }

    private function esDueno(User $user, Catalogo $catalogo): Response
    {
        return $catalogo->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
