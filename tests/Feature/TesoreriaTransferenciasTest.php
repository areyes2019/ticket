<?php

use App\Enums\TipoMovimiento;
use App\Models\Cuenta;
use App\Models\Movimiento;
use App\Models\User;
use App\Services\Tesoreria\RegistradorMovimientos;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->caja = Cuenta::factory()->for($this->user)->conSaldo('1000.00')->create(['nombre' => 'Caja']);
    $this->banco = Cuenta::factory()->for($this->user)->conSaldo('200.00')->create(['nombre' => 'Banco']);
    $this->transferir = fn (array $cambios = []) => $this->actingAs($this->user)
        ->from('/tesoreria/movimientos')
        ->post('/tesoreria/transferencias', [
            'cuenta_origen_id' => $this->caja->id,
            'cuenta_destino_id' => $this->banco->id,
            'monto' => '300.00',
            'fecha' => today('America/Mexico_City')->toDateString(),
            'concepto' => 'Depósito',
            ...$cambios,
        ]);
});

it('crea dos movimientos vinculados sin cambiar el total global', function () {
    ($this->transferir)()
        ->assertRedirect('/tesoreria/movimientos')
        ->assertSessionHas('exito', 'Transferencia de $300.00 de Caja a Banco registrada.');

    $filas = Movimiento::orderBy('id')->get();

    expect($filas)->toHaveCount(2)
        ->and($filas->pluck('tipo')->unique()->all())->toBe([TipoMovimiento::Transferencia])
        ->and($filas[0]->transferencia_id)->not->toBeNull()->toBe($filas[1]->transferencia_id)
        ->and($filas[0]->only(['cuenta_id', 'monto']))->toBe(['cuenta_id' => $this->caja->id, 'monto' => '-300.00'])
        ->and($filas[1]->only(['cuenta_id', 'monto']))->toBe(['cuenta_id' => $this->banco->id, 'monto' => '300.00'])
        ->and($this->caja->fresh()->saldo_actual)->toBe('700.00')
        ->and($this->banco->fresh()->saldo_actual)->toBe('500.00');
});

it('rechaza transferencias inválidas sin guardar nada', function (array $cambios, string $campo) {
    $cambios = array_map(fn ($valor) => is_string($valor) && str_starts_with($valor, '@') ? $this->{substr($valor, 1)}->id : $valor, $cambios);

    ($this->transferir)($cambios)->assertSessionHasErrorsIn('transferencia', $campo);

    expect(Movimiento::count())->toBe(0)
        ->and($this->caja->fresh()->saldo_actual)->toBe('1000.00');
})->with([
    'misma cuenta' => [['cuenta_destino_id' => '@caja'], 'cuenta_destino_id'],
    'sin saldo suficiente' => [['monto' => '1000.01'], 'monto'],
    'monto 0' => [['monto' => '0'], 'monto'],
    'sin concepto' => [['concepto' => ''], 'concepto'],
]);

it('rechaza una cuenta inactiva', function () {
    $this->banco->update(['activa' => false]);

    ($this->transferir)()->assertSessionHasErrorsIn('transferencia', ['cuenta_destino_id' => 'La cuenta Banco está inactiva y no admite movimientos.']);

    expect(Movimiento::count())->toBe(0);
});

it('eliminar una fila elimina las dos', function () {
    ($this->transferir)();

    $this->actingAs($this->user)->delete('/tesoreria/movimientos/'.Movimiento::latest('id')->first()->id)
        ->assertSessionHas('exito', 'Transferencia eliminada.');

    expect(Movimiento::count())->toBe(0)
        ->and($this->caja->fresh()->saldo_actual)->toBe('1000.00')
        ->and($this->banco->fresh()->saldo_actual)->toBe('200.00');
});

it('no se elimina si la destino ya gastó el dinero', function () {
    ($this->transferir)();
    app(RegistradorMovimientos::class)->registrar($this->banco, TipoMovimiento::Egreso, '450', today()->toDateString(), 'Renta');

    $this->actingAs($this->user)->delete('/tesoreria/movimientos/'.Movimiento::where('tipo', 'transferencia')->first()->id)
        ->assertSessionHas('error');

    expect(Movimiento::count())->toBe(3)
        ->and($this->banco->fresh()->saldo_actual)->toBe('50.00');
});

it('una transferencia no se edita', function () {
    ($this->transferir)();
    $fila = Movimiento::first();

    $this->actingAs($this->user)->get("/tesoreria/movimientos/{$fila->id}/editar")->assertForbidden();
    $this->actingAs($this->user)->put("/tesoreria/movimientos/{$fila->id}", [
        'cuenta_id' => $this->caja->id, 'monto' => '1', 'fecha' => today('America/Mexico_City')->toDateString(), 'concepto' => 'X',
    ])->assertForbidden();
});

it('el listado muestra la cuenta del otro lado', function () {
    ($this->transferir)();

    $this->actingAs($this->user)->get('/tesoreria/movimientos')
        ->assertSee('→ Banco')
        ->assertSee('← Caja');
});
