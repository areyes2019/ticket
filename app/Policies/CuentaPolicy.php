<?php

namespace App\Policies;

use App\Models\Cuenta;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Una cuenta ajena responde 404 en todas las acciones.
 */
class CuentaPolicy
{
    public function update(User $user, Cuenta $cuenta): Response
    {
        return $this->esDueno($user, $cuenta);
    }

    public function delete(User $user, Cuenta $cuenta): Response
    {
        return $this->esDueno($user, $cuenta);
    }

    private function esDueno(User $user, Cuenta $cuenta): Response
    {
        return $cuenta->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
