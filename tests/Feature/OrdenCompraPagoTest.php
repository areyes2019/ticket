<?php

use App\Enums\EstadoOrdenCompra;
use App\Enums\TipoMovimiento;
use App\Models\Cuenta;
use App\Models\Movimiento;
use App\Models\OrdenCompra;
use App\Models\Proveedor;
use App\Models\User;

/**
 * Orden de $417.60 (2 × $180 + IVA) del usuario de la prueba.
 */
function ordenParaPagar(User $user, EstadoOrdenCompra $estado = EstadoOrdenCompra::Enviada): OrdenCompra
{
    return OrdenCompra::factory()
        ->for(Proveedor::factory()->for($user))
        ->conLinea(2, '180.00')
        ->enEstado($estado)
        ->create();
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->orden = ordenParaPagar($this->user);
    $this->cuenta = Cuenta::factory()->for($this->user)->conSaldo('800.00')->create(['nombre' => 'BBVA']);
    $this->hoy = today(config('app.zona_negocio'))->toDateString();
    $this->pagar = fn (array $datos = []) => $this->actingAs($this->user)->post("/ordenes-compra/{$this->orden->id}/pago", [
        'cuenta_id' => $this->cuenta->id,
        'fecha_pago' => $this->hoy,
        ...$datos,
    ]);
});

it('crea el egreso por el total, ignora un monto manipulado y deja la orden pagada', function () {
    ($this->pagar)(['monto' => '1.00'])
        ->assertRedirect(route('ordenes-compra.show', $this->orden))
        ->assertSessionHas('exito', 'Pago de $417.60 registrado en BBVA. La orden quedó pagada.');

    $orden = $this->orden->fresh();
    $movimiento = Movimiento::sole();

    expect($orden)
        ->estado->toBe(EstadoOrdenCompra::Pagada)
        ->cuenta_id->toBe($this->cuenta->id)
        ->fecha_pago->toDateString()->toBe($this->hoy)
        ->and($movimiento)
        ->tipo->toBe(TipoMovimiento::Egreso)
        ->monto->toBe('-417.60')
        ->concepto->toBe('Pago de Orden de compra OC-0001')
        ->fecha->toDateString()->toBe($this->hoy)
        ->and($movimiento->documentable->is($this->orden))->toBeTrue()
        ->and($this->cuenta->fresh()->saldo_actual)->toBe('382.40');
});

it('solo se paga una orden enviada', function (EstadoOrdenCompra $estado) {
    $this->orden->forceFill(['estado' => $estado])->save();

    ($this->pagar)()->assertSessionHasErrorsIn('pago', 'cuenta_id');

    expect(Movimiento::count())->toBe(0)
        ->and($this->orden->fresh()->estado)->toBe($estado);
})->with([EstadoOrdenCompra::Borrador, EstadoOrdenCompra::Pagada, EstadoOrdenCompra::Recibida]);

it('rechaza el pago que dejaría la cuenta en negativo sin guardar nada', function () {
    $this->cuenta->forceFill(['saldo_inicial' => '300.00', 'saldo_actual' => '300.00'])->save();

    ($this->pagar)()->assertSessionHasErrorsIn('pago', ['cuenta_id' => 'El movimiento dejaría la cuenta BBVA con saldo negativo.']);

    expect(Movimiento::count())->toBe(0)
        ->and($this->orden->fresh())->estado->toBe(EstadoOrdenCompra::Enviada)->cuenta_id->toBeNull()
        ->and($this->cuenta->fresh()->saldo_actual)->toBe('300.00');
});

it('rechaza cuenta inactiva, cuenta ajena y fecha futura', function () {
    $inactiva = Cuenta::factory()->for($this->user)->conSaldo('900.00')->inactiva()->create();
    $ajena = Cuenta::factory()->conSaldo('900.00')->create();

    ($this->pagar)(['cuenta_id' => $inactiva->id])->assertSessionHasErrorsIn('pago', 'cuenta_id');
    ($this->pagar)(['cuenta_id' => $ajena->id])->assertSessionHasErrorsIn('pago', 'cuenta_id');
    ($this->pagar)(['fecha_pago' => today(config('app.zona_negocio'))->addDay()->toDateString()])->assertSessionHasErrorsIn('pago', 'fecha_pago');

    expect(Movimiento::count())->toBe(0);
});

it('cancelar el pago borra el egreso, devuelve el saldo y regresa a enviada', function () {
    ($this->pagar)();
    $this->cuenta->forceFill(['activa' => false])->save();

    $this->actingAs($this->user)->delete("/ordenes-compra/{$this->orden->id}/pago")
        ->assertSessionHas('exito', 'Pago cancelado. La orden volvió a Enviada.');

    expect(Movimiento::count())->toBe(0)
        ->and($this->orden->fresh())->estado->toBe(EstadoOrdenCompra::Enviada)->cuenta_id->toBeNull()->fecha_pago->toBeNull()
        ->and($this->cuenta->fresh()->saldo_actual)->toBe('800.00');
});

it('una orden recibida no admite cancelar su pago', function () {
    ($this->pagar)();
    $this->actingAs($this->user)->post("/ordenes-compra/{$this->orden->id}/recibir");

    $this->actingAs($this->user)->delete("/ordenes-compra/{$this->orden->id}/pago")
        ->assertSessionHas('error', 'Una orden recibida no admite cancelar su pago.');

    expect(Movimiento::count())->toBe(1)
        ->and($this->orden->fresh()->estado)->toBe(EstadoOrdenCompra::Recibida);
});

it('el egreso no se edita ni se elimina desde Contabilidad y enlaza a la orden', function () {
    ($this->pagar)();
    $movimiento = Movimiento::sole();

    $this->actingAs($this->user)->get("/tesoreria/movimientos/{$movimiento->id}/editar")->assertForbidden();
    $this->actingAs($this->user)->delete("/tesoreria/movimientos/{$movimiento->id}")->assertForbidden();

    $this->actingAs($this->user)->get('/tesoreria/movimientos')
        ->assertOk()
        ->assertSee(route('ordenes-compra.show', $this->orden))
        ->assertSee('OC-0001')
        ->assertDontSee('No disponible');
});

it('el pago de una orden ajena responde 404', function () {
    $ajena = OrdenCompra::factory()->conLinea()->enEstado(EstadoOrdenCompra::Enviada)->create();

    $this->actingAs($this->user)->post("/ordenes-compra/{$ajena->id}/pago", ['cuenta_id' => $this->cuenta->id, 'fecha_pago' => $this->hoy])->assertNotFound();
    $this->actingAs($this->user)->delete("/ordenes-compra/{$ajena->id}/pago")->assertNotFound();
});
