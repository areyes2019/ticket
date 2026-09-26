<?php

namespace App\Policies;

use App\Models\Cliente;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ClientePolicy
{
    /**
     * Solo el dueño puede editar el cliente; a los demás se les responde 404.
     */
    public function update(User $user, Cliente $cliente): Response
    {
        return $this->esDueno($user, $cliente);
    }

    /**
     * Solo el dueño puede eliminar el cliente; a los demás se les responde 404.
     */
    public function delete(User $user, Cliente $cliente): Response
    {
        return $this->esDueno($user, $cliente);
    }

    private function esDueno(User $user, Cliente $cliente): Response
    {
        return $cliente->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
