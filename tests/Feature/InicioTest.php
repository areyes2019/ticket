<?php

it('muestra la vista de inicio con los recursos locales', function () {
    $this->get('/')
        ->assertOk()
        ->assertViewIs('inicio')
        ->assertSee('<meta name="csrf-token"', false)
        ->assertSee(asset('css/app.css'), false)
        ->assertSee(asset('vendor/axios.min.js'), false)
        ->assertSee(asset('js/app.js'), false)
        ->assertSee(asset('js/inicio.js'), false);
});

it('responde JSON en el endpoint de estado', function () {
    $this->getJson('/estado')
        ->assertOk()
        ->assertJson(['estado' => 'ok']);
});

it('recibe un POST y devuelve JSON', function () {
    $this->postJson('/eco', ['mensaje' => 'hola'])
        ->assertOk()
        ->assertExactJson(['recibido' => 'hola']);
});

it('valida el mensaje del POST', function () {
    $this->postJson('/eco', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('mensaje');
});
