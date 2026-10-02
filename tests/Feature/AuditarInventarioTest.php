<?php

use App\Enums\MotivoMovimientoInventario;
use App\Models\Existencia;
use App\Models\User;
use App\Services\Inventario\RegistradorInventario;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->articulo = articuloFacturable($this->user, ['modelo' => 'S-100']);
    app(RegistradorInventario::class)->ajustar($this->articulo, 5, MotivoMovimientoInventario::EntradaInicial, null);
});

it('sin descuadres termina bien', function () {
    $this->artisan('inventario:auditar')
        ->expectsOutputToContain('Sin descuadres')
        ->assertSuccessful();
});

it('reporta un descuadre introducido a mano y no lo corrige', function () {
    Existencia::sole()->forceFill(['existencia' => 9])->save();

    $this->artisan('inventario:auditar')
        ->expectsOutputToContain('S-100')
        ->expectsOutputToContain('1 artículo con descuadre')
        ->assertFailed();

    expect(Existencia::sole()->existencia)->toBe(9);
});

it('limita la revisión a un usuario', function () {
    Existencia::sole()->forceFill(['existencia' => 9])->save();
    $otro = User::factory()->create();

    $this->artisan('inventario:auditar', ['--usuario' => $otro->id])->assertSuccessful();
});

it('también revisa una fila quitada de existencias', function () {
    app(RegistradorInventario::class)->quitar($this->articulo);
    Existencia::withTrashed()->sole()->forceFill(['existencia' => 1])->save();

    $this->artisan('inventario:auditar')->assertFailed();
});
