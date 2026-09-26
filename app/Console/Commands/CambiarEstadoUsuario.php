<?php

namespace App\Console\Commands;

use App\Enums\EstadoUsuario;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('usuario:estado {email : Correo del usuario} {estado : activo o suspendido}')]
#[Description('Activa o suspende a un usuario')]
class CambiarEstadoUsuario extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $estado = EstadoUsuario::tryFrom(Str::lower($this->argument('estado')));

        if (! $estado) {
            $this->error('Estado no válido. Usa: activo o suspendido.');

            return self::FAILURE;
        }

        $user = User::where('email', Str::lower($this->argument('email')))->first();

        if (! $user) {
            $this->error('No existe un usuario con ese correo.');

            return self::FAILURE;
        }

        $user->estado = $estado;
        $user->save();

        $this->info("{$user->email} ahora está {$estado->value}.");

        return self::SUCCESS;
    }
}
