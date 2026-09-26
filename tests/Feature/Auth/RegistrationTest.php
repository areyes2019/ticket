<?php

use App\Enums\EstadoUsuario;
use App\Enums\Rol;
use App\Models\User;

it('muestra la pantalla de registro', function () {
    $this->get('/registro')->assertOk()->assertSee('Crear cuenta');
});

it('registra un usuario normal y activo y lo deja en el dashboard', function () {
    $this->post('/registro', [
        'name' => 'Ana López',
        'email' => 'Ana@Example.com',
        'password' => 'Segura#123',
        'password_confirmation' => 'Segura#123',
    ])->assertRedirect(route('dashboard', absolute: false));

    $user = User::sole();

    $this->assertAuthenticatedAs($user);
    expect($user->email)->toBe('ana@example.com')
        ->and($user->rol)->toBe(Rol::Usuario)
        ->and($user->estado)->toBe(EstadoUsuario::Activo);
});

it('rechaza contraseñas que no cumplen las reglas', function (string $password) {
    $this->post('/registro', [
        'name' => 'Ana López',
        'email' => 'ana@example.com',
        'password' => $password,
        'password_confirmation' => $password,
    ])->assertSessionHasErrors('password');

    $this->assertGuest();
})->with([
    'menos de 8' => 'Ab#1234',
    'sin mayúscula' => 'segura#123',
    'sin minúscula' => 'SEGURA#123',
    'sin número' => 'Segura#abc',
    'sin símbolo' => 'Segura1234',
]);

it('rechaza un correo ya registrado', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    $this->post('/registro', [
        'name' => 'Ana López',
        'email' => 'ANA@example.com',
        'password' => 'Segura#123',
        'password_confirmation' => 'Segura#123',
    ])->assertSessionHasErrors(['email' => 'Este correo electrónico ya está registrado.']);
});
