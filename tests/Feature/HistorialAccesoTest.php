<?php

use App\Models\IntentoAcceso;
use App\Models\User;

it('muestra el historial de accesos al administrador', function () {
    IntentoAcceso::factory()->create(['email' => 'intruso@example.com']);

    $this->actingAs(User::factory()->administrador()->create())
        ->get('/historial-accesos')
        ->assertOk()
        ->assertSee('intruso@example.com');
});

it('no permite ver el historial a un usuario normal', function () {
    $this->actingAs(User::factory()->create())
        ->get('/historial-accesos')
        ->assertForbidden();
});

it('pide iniciar sesión para ver el historial', function () {
    $this->get('/historial-accesos')->assertRedirect(route('login'));
});
