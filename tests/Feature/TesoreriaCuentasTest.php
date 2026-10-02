<?php

use App\Enums\TipoCuenta;
use App\Enums\TipoMovimiento;
use App\Models\Cuenta;
use App\Models\User;
use App\Services\Tesoreria\RegistradorMovimientos;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('pide sesión', function () {
    $this->get('/tesoreria/cuentas')->assertRedirect('/login');
});

it('crea una cuenta con el saldo actual igual al inicial', function () {
    $this->actingAs($this->user)->post('/tesoreria/cuentas', [
        'nombre' => 'BBVA',
        'tipo' => 'banco',
        'saldo_inicial' => '1500.50',
        'saldo_actual' => '99999',
    ])->assertRedirect('/tesoreria/cuentas')->assertSessionHas('exito');

    expect(Cuenta::sole())
        ->user_id->toBe($this->user->id)
        ->nombre->toBe('BBVA')
        ->tipo->toBe(TipoCuenta::Banco)
        ->saldo_inicial->toBe('1500.50')
        ->saldo_actual->toBe('1500.50')
        ->activa->toBeTrue();
});

it('valida el alta', function (array $datos, string $campo) {
    $this->actingAs($this->user)->post('/tesoreria/cuentas', [
        'nombre' => 'Caja', 'tipo' => 'efectivo', 'saldo_inicial' => '0', ...$datos,
    ])->assertSessionHasErrors($campo);

    expect(Cuenta::count())->toBe(0);
})->with([
    'sin nombre' => [['nombre' => ''], 'nombre'],
    'tipo inexistente' => [['tipo' => 'cripto'], 'tipo'],
    'saldo negativo' => [['saldo_inicial' => '-1'], 'saldo_inicial'],
    'sin saldo' => [['saldo_inicial' => ''], 'saldo_inicial'],
    'tres decimales' => [['saldo_inicial' => '1.005'], 'saldo_inicial'],
]);

it('la edición ignora el saldo inicial y puede desactivar', function () {
    $cuenta = Cuenta::factory()->for($this->user)->conSaldo('100.00')->create();

    $this->actingAs($this->user)->put("/tesoreria/cuentas/{$cuenta->id}", [
        'nombre' => 'Caja chica',
        'tipo' => 'efectivo',
        'saldo_inicial' => '5000',
    ])->assertRedirect('/tesoreria/cuentas');

    expect($cuenta->fresh())
        ->nombre->toBe('Caja chica')
        ->tipo->toBe(TipoCuenta::Efectivo)
        ->saldo_inicial->toBe('100.00')
        ->saldo_actual->toBe('100.00')
        ->activa->toBeFalse();
});

it('el formulario de edición muestra el saldo inicial de solo lectura', function () {
    $cuenta = Cuenta::factory()->for($this->user)->conSaldo('100.00')->create();

    $this->actingAs($this->user)->get("/tesoreria/cuentas/{$cuenta->id}/editar")
        ->assertOk()
        ->assertSee('Para corregirlo, registra un ajuste')
        ->assertDontSee('name="saldo_inicial"', false);
});

it('activa y desactiva', function () {
    $cuenta = Cuenta::factory()->for($this->user)->create();

    $this->actingAs($this->user)->patch("/tesoreria/cuentas/{$cuenta->id}/activa")->assertSessionHas('exito', "Cuenta {$cuenta->nombre} desactivada.");
    expect($cuenta->fresh()->activa)->toBeFalse();

    $this->actingAs($this->user)->patch("/tesoreria/cuentas/{$cuenta->id}/activa");
    expect($cuenta->fresh()->activa)->toBeTrue();
});

it('borra una cuenta sin movimientos', function () {
    $cuenta = Cuenta::factory()->for($this->user)->create();

    $this->actingAs($this->user)->delete("/tesoreria/cuentas/{$cuenta->id}")->assertSessionHas('exito');

    expect(Cuenta::count())->toBe(0);
});

it('no borra una cuenta con movimientos y ofrece desactivarla', function () {
    $cuenta = Cuenta::factory()->for($this->user)->create();
    app(RegistradorMovimientos::class)->registrar($cuenta, TipoMovimiento::Ingreso, '10', today()->toDateString(), 'Venta');

    $this->actingAs($this->user)->delete("/tesoreria/cuentas/{$cuenta->id}")
        ->assertRedirect('/tesoreria/cuentas')
        ->assertSessionHas('error', 'No se puede eliminar: la cuenta tiene movimientos registrados');

    expect(Cuenta::count())->toBe(1);

    $this->actingAs($this->user)->followingRedirects()->delete("/tesoreria/cuentas/{$cuenta->id}")
        ->assertSee('Desactivar cuenta');
});

it('busca por nombre y filtra por estado', function () {
    Cuenta::factory()->for($this->user)->create(['nombre' => 'BBVA']);
    Cuenta::factory()->for($this->user)->inactiva()->create(['nombre' => 'Banamex']);
    Cuenta::factory()->for($this->user)->create(['nombre' => 'Caja General']);
    Cuenta::factory()->create(['nombre' => 'Banco ajeno']);

    $this->actingAs($this->user)->get('/tesoreria/cuentas?buscar=b')
        ->assertSee('BBVA')->assertSee('Banamex')->assertDontSee('Caja General')->assertDontSee('Banco ajeno');

    $this->actingAs($this->user)->get('/tesoreria/cuentas?activa=0')
        ->assertSee('Banamex')->assertDontSee('BBVA');
});

it('responde 404 con una cuenta ajena', function (string $metodo, string $sufijo) {
    $ajena = Cuenta::factory()->create();

    $this->actingAs($this->user)->call($metodo, "/tesoreria/cuentas/{$ajena->id}{$sufijo}", ['nombre' => 'X', 'tipo' => 'banco'])
        ->assertNotFound();

    expect($ajena->fresh())->not->toBeNull();
})->with([
    'editar' => ['GET', '/editar'],
    'actualizar' => ['PUT', ''],
    'eliminar' => ['DELETE', ''],
    'alternar' => ['PATCH', '/activa'],
]);

it('el menú lleva a Contabilidad', function () {
    $this->actingAs($this->user)->get('/tesoreria/cuentas')
        ->assertSee('Contabilidad')
        ->assertSee(route('tesoreria.movimientos.index'), false)
        ->assertSee(route('tesoreria.saldos'), false);
});
