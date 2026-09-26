<?php

namespace App\Policies;

use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProveedorPolicy
{
    /**
     * Solo el dueño puede editar el proveedor; a los demás se les responde 404.
     */
    public function update(User $user, Proveedor $proveedor): Response
    {
        return $this->esDueno($user, $proveedor);
    }

    /**
     * Solo el dueño puede eliminar el proveedor; a los demás se les responde 404.
     */
    public function delete(User $user, Proveedor $proveedor): Response
    {
        return $this->esDueno($user, $proveedor);
    }

    private function esDueno(User $user, Proveedor $proveedor): Response
    {
        return $proveedor->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
