<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WhatsappConversacion;

/**
 * La conversación es de la cuenta dueña del número (041, regla 61).
 */
class WhatsappConversacionPolicy
{
    public function view(User $user, WhatsappConversacion $conversacion): bool
    {
        return $conversacion->user_id === $user->id;
    }
}
