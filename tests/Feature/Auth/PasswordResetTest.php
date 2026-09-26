<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

it('muestra la pantalla "¿Olvidaste tu contraseña?"', function () {
    $this->get('/olvide-contrasena')->assertOk();
});

it('envía el enlace de recuperación en español', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/olvide-contrasena', ['email' => $user->email])
        ->assertSessionHas('status');

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $correo = $notification->toMail($user);

        return $correo->subject === 'Restablece tu contraseña'
            && in_array('Este enlace vence en 15 minutos.', array_merge($correo->introLines, $correo->outroLines), true);
    });
});

it('rechaza la cuarta solicitud dentro de 15 minutos', function () {
    Notification::fake();

    $user = User::factory()->create();

    foreach (range(1, 3) as $solicitud) {
        $this->post('/olvide-contrasena', ['email' => $user->email])->assertSessionHasNoErrors();
    }

    $this->post('/olvide-contrasena', ['email' => $user->email])
        ->assertSessionHasErrors(['email' => 'Ya pediste varios enlaces. Espera 15 minutos antes de volver a intentarlo.']);

    Notification::assertSentToTimes($user, ResetPassword::class, 3);

    $this->travel(15)->minutes();

    $this->post('/olvide-contrasena', ['email' => $user->email])->assertSessionHasNoErrors();
});

it('muestra el formulario de nueva contraseña con un enlace válido', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->get("/restablecer-contrasena/{$token}?email={$user->email}")
        ->assertOk()
        ->assertSee('Nueva contraseña');
});

it('avisa y ofrece pedir otro enlace cuando el enlace venció', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->travel(16)->minutes();

    $this->get("/restablecer-contrasena/{$token}?email={$user->email}")
        ->assertOk()
        ->assertSee('El enlace para cambiar la contraseña ya venció o no es válido.')
        ->assertSee(route('password.request'));
});

it('cambia la contraseña e inicia la sesión', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->post('/restablecer-contrasena', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'Nueva#2026',
        'password_confirmation' => 'Nueva#2026',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(Hash::check('Nueva#2026', $user->fresh()->password))->toBeTrue();
});

it('rechaza una nueva contraseña que no cumple las reglas', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->post('/restablecer-contrasena', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'simple',
        'password_confirmation' => 'simple',
    ])->assertSessionHasErrors('password');

    $this->assertGuest();
});

it('no inicia la sesión de un usuario suspendido al cambiar su contraseña', function () {
    $user = User::factory()->suspendido()->create();
    $token = Password::createToken($user);

    $this->post('/restablecer-contrasena', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'Nueva#2026',
        'password_confirmation' => 'Nueva#2026',
    ])->assertRedirect(route('login'));

    $this->assertGuest();
});
