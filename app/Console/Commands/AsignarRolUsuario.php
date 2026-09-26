<?php

namespace App\Console\Commands;

use App\Enums\Rol;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('usuario:rol {email : Correo del usuario} {rol : usuario o administrador}')]
#[Description('Asigna el rol de un usuario')]
class AsignarRolUsuario extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rol = Rol::tryFrom(Str::lower($this->argument('rol')));

        if (! $rol) {
            $this->error('Rol no válido. Usa: usuario o administrador.');

            return self::FAILURE;
        }

        $user = User::where('email', Str::lower($this->argument('email')))->first();

        if (! $user) {
            $this->error('No existe un usuario con ese correo.');

            return self::FAILURE;
        }

        $user->rol = $rol;
        $user->save();

        $this->info("{$user->email} ahora tiene el rol {$rol->value}.");

        return self::SUCCESS;
    }
}
