<?php

use App\Enums\TipoMovimiento;
use App\Models\Cuenta;
use App\Models\Movimiento;
use App\Models\User;
use App\Services\Tesoreria\RegistradorMovimientos;

/**
 * @param  array<string, mixed>  $cambios
 * @return array<string, mixed>
 */
function datosMovimiento(string $tipo, array $cambios = []): array
{
    return ['tipo' => $tipo, 'monto' => '100.00', 'fecha' => today('America/Mexico_City')->toDateString(), 'concepto' => 'Ventas varias', ...$cambios];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->cuenta = Cuenta::factory()->for($this->user)->conSaldo('500.00')->create(['nombre' => 'Caja']);
    $this->registrar = fn (array $datos) => $this->actingAs($this->user)
        ->from('/tesoreria/movimientos')
        ->post('/tesoreria/movimientos', ['cuenta_id' => $this->cuenta->id, ...$datos]);
});

it('ingreso suma, egreso resta y ajuste corrige según su signo', function (string $tipo, string $monto, string $guardado, string $saldo) {
    ($this->registrar)(datosMovimiento($tipo, ['monto' => $monto]))
        ->assertRedirect('/tesoreria/movimientos')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('exito');

    expect(Movimiento::sole())
        ->tipo->toBe(TipoMovimiento::from($tipo))
        ->monto->toBe($guardado)
        ->user_id->toBe($this->user->id)
        ->esAutomatico()->toBeFalse()
        ->and($this->cuenta->fresh()->saldo_actual)->toBe($saldo);
})->with([
    'ingreso' => ['ingreso', '150.25', '150.25', '650.25'],
    'egreso' => ['egreso', '150.25', '-150.25', '349.75'],
    'ajuste positivo' => ['ajuste', '20', '20.00', '520.00'],
    'ajuste negativo' => ['ajuste', '-20', '-20.00', '480.00'],
]);

it('rechaza lo que dejaría la cuenta en negativo y no guarda nada', function (string $tipo, string $monto) {
    ($this->registrar)(datosMovimiento($tipo, ['monto' => $monto]))
        ->assertSessionHasErrorsIn($tipo, ['monto' => 'El movimiento dejaría la cuenta Caja con saldo negativo.']);

    expect(Movimiento::count())->toBe(0)
        ->and($this->cuenta->fresh()->saldo_actual)->toBe('500.00');
})->with([
    'egreso' => ['egreso', '500.01'],
    'ajuste negativo' => ['ajuste', '-500.01'],
]);

it('permite dejar la cuenta exactamente en cero', function () {
    ($this->registrar)(datosMovimiento('egreso', ['monto' => '500']))->assertSessionHasNoErrors();

    expect($this->cuenta->fresh()->saldo_actual)->toBe('0.00');
});

it('valida el movimiento en la bolsa de su tipo', function (string $tipo, array $cambios, string $campo) {
    ($this->registrar)(datosMovimiento($tipo, $cambios))->assertSessionHasErrorsIn($tipo, $campo);

    expect(Movimiento::count())->toBe(0);
})->with([
    'ingreso en 0' => ['ingreso', ['monto' => '0'], 'monto'],
    'egreso negativo' => ['egreso', ['monto' => '-5'], 'monto'],
    'ajuste en 0' => ['ajuste', ['monto' => '0.00'], 'monto'],
    'tres decimales' => ['ingreso', ['monto' => '1.005'], 'monto'],
    'fecha futura' => ['ingreso', ['fecha' => today('America/Mexico_City')->addDays(2)->toDateString()], 'fecha'],
    'sin concepto' => ['egreso', ['concepto' => ''], 'concepto'],
    'sin cuenta' => ['ingreso', ['cuenta_id' => ''], 'cuenta_id'],
]);

it('no acepta transferencias por el formulario de movimiento', function () {
    ($this->registrar)(datosMovimiento('transferencia'))->assertSessionHasErrors('tipo');

    expect(Movimiento::count())->toBe(0);
});

it('rechaza una cuenta inactiva o ajena', function () {
    $inactiva = Cuenta::factory()->for($this->user)->inactiva()->create(['nombre' => 'Vieja']);
    $ajena = Cuenta::factory()->create();

    ($this->registrar)(datosMovimiento('ingreso', ['cuenta_id' => $inactiva->id]))
        ->assertSessionHasErrorsIn('ingreso', ['cuenta_id' => 'La cuenta Vieja está inactiva y no admite movimientos.']);
    ($this->registrar)(datosMovimiento('ingreso', ['cuenta_id' => $ajena->id]))
        ->assertSessionHasErrorsIn('ingreso', 'cuenta_id');

    expect(Movimiento::count())->toBe(0);
});

it('edita un movimiento manual y recalcula el saldo', function () {
    ($this->registrar)(datosMovimiento('egreso', ['monto' => '100']));
    $movimiento = Movimiento::sole();

    $this->actingAs($this->user)->get("/tesoreria/movimientos/{$movimiento->id}/editar")
        ->assertOk()->assertSee('value="100.00"', false);

    $this->actingAs($this->user)->put("/tesoreria/movimientos/{$movimiento->id}", [
        'cuenta_id' => $this->cuenta->id, 'monto' => '40', 'fecha' => '2026-01-15', 'concepto' => 'Papelería',
    ])->assertRedirect('/tesoreria/movimientos')->assertSessionHasNoErrors();

    expect($movimiento->fresh())
        ->monto->toBe('-40.00')
        ->concepto->toBe('Papelería')
        ->fecha->toDateString()->toBe('2026-01-15')
        ->and($this->cuenta->fresh()->saldo_actual)->toBe('460.00');
});

it('al moverlo de cuenta, las dos cuadran', function () {
    $banco = Cuenta::factory()->for($this->user)->create();
    ($this->registrar)(datosMovimiento('ingreso', ['monto' => '100']));
    $movimiento = Movimiento::sole();

    $this->actingAs($this->user)->put("/tesoreria/movimientos/{$movimiento->id}", [
        'cuenta_id' => $banco->id, 'monto' => '100', 'fecha' => today('America/Mexico_City')->toDateString(), 'concepto' => 'Ventas',
    ])->assertSessionHasNoErrors();

    expect($this->cuenta->fresh()->saldo_actual)->toBe('500.00')
        ->and($banco->fresh()->saldo_actual)->toBe('100.00');
});

it('no edita un ingreso a la baja si la cuenta quedaría en negativo', function () {
    ($this->registrar)(datosMovimiento('ingreso', ['monto' => '100']));
    ($this->registrar)(datosMovimiento('egreso', ['monto' => '600']));
    $ingreso = Movimiento::where('tipo', 'ingreso')->sole();

    $this->actingAs($this->user)->from("/tesoreria/movimientos/{$ingreso->id}/editar")->put("/tesoreria/movimientos/{$ingreso->id}", [
        'cuenta_id' => $this->cuenta->id, 'monto' => '50', 'fecha' => today('America/Mexico_City')->toDateString(), 'concepto' => 'Ventas',
    ])->assertSessionHasErrors('monto');

    expect($ingreso->fresh()->monto)->toBe('100.00')
        ->and($this->cuenta->fresh()->saldo_actual)->toBe('0.00');
});

it('elimina un movimiento manual y recalcula el saldo', function () {
    ($this->registrar)(datosMovimiento('egreso', ['monto' => '100']));

    $this->actingAs($this->user)->delete('/tesoreria/movimientos/'.Movimiento::sole()->id)->assertSessionHas('exito');

    expect(Movimiento::count())->toBe(0)
        ->and($this->cuenta->fresh()->saldo_actual)->toBe('500.00');
});

it('no elimina un ingreso ya gastado', function () {
    $cuenta = Cuenta::factory()->for($this->user)->create(['nombre' => 'Vacía']);
    $registrador = app(RegistradorMovimientos::class);
    $ingreso = $registrador->registrar($cuenta, TipoMovimiento::Ingreso, '100', today()->toDateString(), 'Venta');
    $registrador->registrar($cuenta, TipoMovimiento::Egreso, '80', today()->toDateString(), 'Compra');

    $this->actingAs($this->user)->delete("/tesoreria/movimientos/{$ingreso->id}")
        ->assertSessionHas('error', 'No se puede eliminar: el movimiento dejaría la cuenta Vacía con saldo negativo.');

    expect($ingreso->fresh())->not->toBeNull()
        ->and($cuenta->fresh()->saldo_actual)->toBe('20.00');
});

it('un movimiento ajeno responde 404', function (string $metodo, string $sufijo) {
    $otro = User::factory()->create();
    $cuenta = Cuenta::factory()->for($otro)->create();
    $ajeno = app(RegistradorMovimientos::class)->registrar($cuenta, TipoMovimiento::Ingreso, '10', today()->toDateString(), 'Venta');

    $this->actingAs($this->user)->call($metodo, "/tesoreria/movimientos/{$ajeno->id}{$sufijo}", [
        'cuenta_id' => $this->cuenta->id, 'monto' => '1', 'fecha' => today('America/Mexico_City')->toDateString(), 'concepto' => 'X',
    ])->assertNotFound();

    expect($ajeno->fresh()->monto)->toBe('10.00');
})->with([
    'editar' => ['GET', '/editar'],
    'actualizar' => ['PUT', ''],
    'eliminar' => ['DELETE', ''],
]);

it('filtra combinando fecha, cuenta, tipo y concepto', function () {
    $banco = Cuenta::factory()->for($this->user)->create(['nombre' => 'Banco']);
    $registrador = app(RegistradorMovimientos::class);
    $registrador->registrar($this->cuenta, TipoMovimiento::Ingreso, '10', '2026-03-01', 'Venta de sellos');
    $registrador->registrar($this->cuenta, TipoMovimiento::Egreso, '10', '2026-03-15', 'Compra de tinta');
    $registrador->registrar($banco, TipoMovimiento::Ingreso, '10', '2026-03-15', 'Venta de tinta');
    $registrador->registrar($this->cuenta, TipoMovimiento::Ingreso, '10', '2026-04-01', 'Venta de tinta abril');
    $ajena = Cuenta::factory()->create();
    $registrador->registrar($ajena, TipoMovimiento::Ingreso, '10', '2026-03-15', 'Venta ajena de tinta');

    $ver = fn (array $filtros) => $this->actingAs($this->user)->get('/tesoreria/movimientos?'.http_build_query($filtros))->assertOk();

    $ver(['fecha_desde' => '2026-03-10', 'fecha_hasta' => '2026-03-31'])
        ->assertSee('Compra de tinta')->assertSee('Venta de tinta')
        ->assertDontSee('Venta de sellos')->assertDontSee('abril')->assertDontSee('ajena');

    $ver(['cuenta_id' => $this->cuenta->id, 'tipo' => 'ingreso', 'concepto' => 'tinta'])
        ->assertSee('Venta de tinta abril')
        ->assertDontSee('Venta de sellos')->assertDontSee('Compra de tinta')->assertDontSee('ajena');

    $ver(['fecha_hasta' => '2026-03-01'])->assertSee('Venta de sellos')->assertDontSee('Compra de tinta');
    $ver(['fecha_desde' => 'basura', 'tipo' => 'otro'])->assertSee('Venta de sellos')->assertSee('abril');
});

it('el saldo siempre es el inicial más la suma de los movimientos', function () {
    $banco = Cuenta::factory()->for($this->user)->conSaldo('1000.00')->create();
    $registrador = app(RegistradorMovimientos::class);
    $hoy = today()->toDateString();

    $registrador->registrar($this->cuenta, TipoMovimiento::Ingreso, '123.45', $hoy, 'A');
    $egreso = $registrador->registrar($this->cuenta, TipoMovimiento::Egreso, '23.40', $hoy, 'B');
    $registrador->registrar($this->cuenta, TipoMovimiento::Ajuste, '-0.05', $hoy, 'C');
    $registrador->transferir($banco, $this->cuenta, '300', $hoy, 'D');
    $registrador->actualizar($egreso, $this->cuenta, '20', $hoy, 'B2');
    $registrador->transferir($this->cuenta, $banco, '50', $hoy, 'E');

    foreach ([$this->cuenta, $banco] as $cuenta) {
        $cuenta->refresh();
        $suma = Movimiento::where('cuenta_id', $cuenta->id)->get()->sum(fn ($m) => (int) round($m->monto * 100));

        expect((int) round($cuenta->saldo_actual * 100))->toBe((int) round($cuenta->saldo_inicial * 100) + $suma);
    }

    expect($this->cuenta->saldo_actual)->toBe('853.40')
        ->and($banco->saldo_actual)->toBe('750.00');
});

it('pinta montos con signo, origen manual y los diálogos', function () {
    ($this->registrar)(datosMovimiento('egreso', ['monto' => '75', 'concepto' => 'Luz']));

    $this->actingAs($this->user)->get('/tesoreria/movimientos')
        ->assertOk()
        ->assertSee('−$75.00')
        ->assertSee('Manual')
        ->assertSee('id="dialogo-ingreso"', false)
        ->assertSee('id="dialogo-egreso"', false)
        ->assertSee('id="dialogo-ajuste"', false)
        ->assertSee('id="dialogo-transferencia"', false);
});

it('sin cuentas activas pide crear una', function () {
    $this->cuenta->update(['activa' => false]);

    $this->actingAs($this->user)->get('/tesoreria/movimientos')
        ->assertSee('primero crea una cuenta')
        ->assertDontSee('id="dialogo-ingreso"', false);
});

it('un error reabre el diálogo de su tipo', function () {
    ($this->registrar)(datosMovimiento('egreso', ['monto' => '9999']));

    $this->actingAs($this->user)->get('/tesoreria/movimientos')
        ->assertSee('id="dialogo-egreso" class="ficha dialogo" aria-labelledby="dialogo-egreso-titulo"  data-abrir-al-cargar', false)
        ->assertSee('saldo negativo');
});
