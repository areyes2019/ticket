<?php

use App\Enums\EstadoUsuario;
use App\Enums\Rol;
use App\Models\User;

it('asigna el rol de administrador desde la consola', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);

    $this->artisan('usuario:rol', ['email' => 'ANA@example.com', 'rol' => 'administrador'])
        ->assertSuccessful();

    expect($user->fresh()->rol)->toBe(Rol::Administrador);
});

it('suspende a un usuario desde la consola', function () {
    $user = User::factory()->create();

    $this->artisan('usuario:estado', ['email' => $user->email, 'estado' => 'suspendido'])
        ->assertSuccessful();

    expect($user->fresh()->estado)->toBe(EstadoUsuario::Suspendido);
});

it('rechaza un rol que no existe', function () {
    $user = User::factory()->create();

    $this->artisan('usuario:rol', ['email' => $user->email, 'rol' => 'jefe'])
        ->assertFailed();

    expect($user->fresh()->rol)->toBe(Rol::Usuario);
});
