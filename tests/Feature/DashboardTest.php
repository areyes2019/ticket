<?php

use App\Models\User;
use App\Support\Demo\BandejaCorreoDemo;

it('muestra la bandeja de correo de demostración en el dashboard', function () {
    $correo = (new BandejaCorreoDemo)->correos()[0];

    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Bandeja de demostración')
        ->assertSee('Bandeja de entrada')
        ->assertSee($correo['asunto'])
        ->assertSee(asset('js/bandeja-correo.js'), false);
});

it('manda al login a quien abre el dashboard sin sesión', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
});
