<?php

use Illuminate\Support\Facades\Blade;

function mensajeDeError(Closure $accion): ?string
{
    try {
        $accion();
    } catch (Throwable $e) {
        return $e->getMessage();
    }

    return null;
}

it('carga Bootstrap Icons desde el proyecto en el layout', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee(asset('vendor/bootstrap-icons/bootstrap-icons.min.css'), false);
});

it('oculta los iconos a los lectores de pantalla', function () {
    expect(Blade::render('<x-icono nombre="eye" />'))
        ->toContain('class="bi bi-eye icono"')
        ->toContain('aria-hidden="true"');
});

it('pinta un botón con icono y texto', function () {
    $html = Blade::render('<x-boton icono="save" variante="secundario">Guardar</x-boton>');

    expect($html)
        ->toContain('<button type="submit"')
        ->toContain('boton boton-secundario')
        ->toContain('bi-save')
        ->toContain('Guardar')
        ->not->toContain('aria-label');
});

it('pinta un enlace con aspecto de botón', function () {
    expect(Blade::render('<x-boton href="/inicio" bloque>Ir</x-boton>'))
        ->toContain('<a href="/inicio"')
        ->toContain('boton-bloque');
});

it('pone la descripción en un botón de solo icono', function () {
    expect(Blade::render('<x-boton icono="eye" descripcion="Mostrar contraseña" />'))
        ->toContain('aria-label="Mostrar contraseña"')
        ->toContain('boton-icono');
});

it('exige descripción en un botón de solo icono', function () {
    $mensaje = mensajeDeError(fn () => Blade::render('<x-boton icono="eye" />'));

    expect($mensaje)->toContain('Un botón de solo icono necesita una descripción.');
});

it('muestra el icono según el tipo de alerta', function (string $tipo, string $icono) {
    expect(Blade::render('<x-alerta :tipo="$tipo">Mensaje</x-alerta>', ['tipo' => $tipo]))
        ->toContain('alerta-'.$tipo)
        ->toContain('bi-'.$icono);
})->with([
    ['exito', 'check-circle'],
    ['error', 'x-circle'],
    ['advertencia', 'exclamation-triangle'],
]);

it('rechaza un tipo de alerta desconocido', function () {
    expect(mensajeDeError(fn () => Blade::render('<x-alerta tipo="rosa">Hola</x-alerta>')))
        ->toContain('Tipo de alerta desconocido');
});

it('pinta el campo de contraseña con el botón de ojo', function () {
    expect(Blade::render('<x-campo nombre="password" etiqueta="Contraseña" tipo="password" required />'))
        ->toContain('type="password"')
        ->toContain('required')
        ->toContain('data-mostrar-contrasena="password"')
        ->toContain('bi-eye')
        ->toContain('aria-label="Mostrar contraseña"');
});

it('muestra el botón de ojo en el login', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('data-mostrar-contrasena="password"', false)
        ->assertSee('bi-eye', false)
        ->assertSee('bi-box-arrow-in-right', false);
});

it('abre la página de estilos en local', function () {
    $this->app['env'] = 'local';

    $this->get('/estilos')
        ->assertOk()
        ->assertSee('Muestra de estilos')
        ->assertSee('campo-error', false);
});

it('oculta la página de estilos fuera de local', function (string $entorno) {
    $this->app['env'] = $entorno;

    $this->get('/estilos')->assertNotFound();
})->with(['testing', 'production', 'staging']);
