<?php

use App\Models\User;

it('manda al login a quien entra a la raíz sin sesión', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('manda al panel a quien entra a la raíz con sesión', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect(route('dashboard'));
});
