<?php

namespace App\Policies;

use App\Models\Articulo;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ArticuloPolicy
{
    /**
     * Solo el dueño puede editar el artículo; a los demás se les responde 404.
     */
    public function update(User $user, Articulo $articulo): Response
    {
        return $this->esDueno($user, $articulo);
    }

    /**
     * Solo el dueño puede eliminar el artículo; a los demás se les responde 404.
     */
    public function delete(User $user, Articulo $articulo): Response
    {
        return $this->esDueno($user, $articulo);
    }

    private function esDueno(User $user, Articulo $articulo): Response
    {
        return $articulo->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
