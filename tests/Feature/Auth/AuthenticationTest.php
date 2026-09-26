<?php

use App\Enums\ResultadoAcceso;
use App\Models\User;

it('muestra la pantalla de inicio de sesión', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Iniciar sesión')
        ->assertSee('Recordarme')
        ->assertSee('¿Olvidaste tu contraseña?')
        ->assertSee('Crear cuenta');
});

it('permite entrar con credenciales correctas y lleva al dashboard', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

it('no distingue mayúsculas en el correo', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);

    $this->post('/login', ['email' => 'ANA@Example.com', 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

it('distingue mayúsculas en la contraseña', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'PASSWORD']);

    $this->assertGuest();
});

it('muestra un mensaje genérico y conserva el correo cuando las credenciales son incorrectas', function () {
    $user = User::factory()->create();

    $this->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'incorrecta'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => 'Credenciales incorrectas.'])
        ->assertSessionHasInput('email', $user->email)
        ->assertSessionMissing('_old_input.password');

    $this->assertGuest();
});

it('bloquea el acceso durante un minuto después de 5 intentos fallidos', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $intento) {
        $this->post('/login', ['email' => $user->email, 'password' => 'incorrecta']);
    }

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'Demasiados intentos fallidos. Espera 60 segundos antes de volver a intentarlo.']);
    $this->assertGuest();

    $this->travel(61)->seconds();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
});

it('no deja entrar a un usuario suspendido aunque su contraseña sea correcta', function () {
    $user = User::factory()->suspendido()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'Tu cuenta está suspendida. Comunícate con el administrador.']);

    $this->assertGuest();
});

it('cierra la sesión de un usuario suspendido mientras estaba dentro', function () {
    $user = User::factory()->suspendido()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('lleva a la página protegida que se intentó abrir antes de entrar', function () {
    $user = User::factory()->administrador()->create();

    $this->get('/historial-accesos')->assertRedirect(route('login'));

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/historial-accesos');
});

it('manda al dashboard a un usuario que ya inició sesión y abre el login', function () {
    $this->actingAs(User::factory()->create())
        ->get('/login')
        ->assertRedirect(route('dashboard'));
});

it('guarda la sesión con "Recordarme"', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1']);

    expect($user->fresh()->remember_token)->not->toBeNull();
});

it('cierra la sesión y lleva al login', function () {
    $this->actingAs(User::factory()->create())
        ->post('/logout')
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('registra cada intento en el historial', function () {
    $user = User::factory()->create();
    $suspendido = User::factory()->suspendido()->create();

    $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36');

    $this->post('/login', ['email' => 'nadie@example.com', 'password' => 'x']);
    $this->post('/login', ['email' => $suspendido->email, 'password' => 'password']);
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->assertDatabaseHas('intentos_acceso', [
        'email' => 'nadie@example.com',
        'user_id' => null,
        'resultado' => ResultadoAcceso::Fallido->value,
    ]);
    $this->assertDatabaseHas('intentos_acceso', [
        'user_id' => $suspendido->id,
        'resultado' => ResultadoAcceso::Suspendido->value,
    ]);
    $this->assertDatabaseHas('intentos_acceso', [
        'user_id' => $user->id,
        'resultado' => ResultadoAcceso::Exitoso->value,
        'ip_address' => '127.0.0.1',
        'navegador' => 'Chrome',
        'dispositivo' => 'Escritorio · Windows',
    ]);
});
