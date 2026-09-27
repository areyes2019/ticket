<?php

use App\Enums\EstadoCotizacion;
use App\Enums\TipoPago;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\User;

/**
 * Cotización de $1,160.00 (10 × $100 + IVA) del usuario de la prueba.
 */
function cotizacionParaPagos(User $user, EstadoCotizacion $estado = EstadoCotizacion::Enviada): Cotizacion
{
    return Cotizacion::factory()
        ->for(Cliente::factory()->for($user))
        ->conLinea(10)
        ->enEstado($estado)
        ->create(['user_id' => $user->id]);
}

/**
 * @param  array<string, mixed>  $cambios
 * @return array<string, mixed>
 */
function datosPago(string $tipo, array $cambios = []): array
{
    return ['tipo' => $tipo, 'fecha_pago' => today()->toDateString(), 'forma_pago' => '03', ...$cambios];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->cotizacion = cotizacionParaPagos($this->user);
    $this->pagar = fn (array $datos) => $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/pagos", $datos);
});

it('un anticipo parcial deja la cotización enviada', function () {
    ($this->pagar)(datosPago('anticipo', ['monto' => '500.00']))->assertSessionHasNoErrors();

    expect($this->cotizacion->fresh())
        ->estado->toBe(EstadoCotizacion::Enviada)
        ->saldoPendiente()->toBe('660.00');
});

it('anticipo más saldo la deja pagada, y el saldo ignora un monto manipulado', function () {
    ($this->pagar)(datosPago('anticipo', ['monto' => '500.00']));
    ($this->pagar)(datosPago('saldo', ['monto' => '1.00']))->assertSessionHasNoErrors();

    $cotizacion = $this->cotizacion->fresh();

    expect($cotizacion->estado)->toBe(EstadoCotizacion::Pagada)
        ->and($cotizacion->pagos->pluck('monto')->all())->toBe(['500.00', '660.00'])
        ->and($cotizacion->totalPagado())->toBe('1160.00');
});

it('el pago total la deja pagada con el total completo', function () {
    ($this->pagar)(datosPago('pago_total', ['monto' => '5.00']))->assertSessionHasNoErrors();

    expect($this->cotizacion->fresh())
        ->estado->toBe(EstadoCotizacion::Pagada)
        ->totalPagado()->toBe('1160.00');
});

it('un anticipo igual al saldo la deja pagada', function () {
    ($this->pagar)(datosPago('anticipo', ['monto' => '1160.00']))->assertSessionHasNoErrors();

    expect($this->cotizacion->fresh()->estado)->toBe(EstadoCotizacion::Pagada);
});

it('rechaza pagos que rompen las reglas', function (array $previos, array $pago) {
    foreach ($previos as $previo) {
        ($this->pagar)($previo)->assertSessionHasNoErrors();
    }

    ($this->pagar)($pago)->assertSessionHasErrorsIn('pago', 'monto');

    expect($this->cotizacion->pagos()->count())->toBe(count($previos));
})->with([
    'segundo anticipo' => [[datosPago('anticipo', ['monto' => '100.00'])], datosPago('anticipo', ['monto' => '100.00'])],
    'anticipo mayor al saldo' => [[], datosPago('anticipo', ['monto' => '1160.01'])],
    'saldo sin anticipo' => [[], datosPago('saldo')],
    'pago total con anticipo' => [[datosPago('anticipo', ['monto' => '100.00'])], datosPago('pago_total')],
    'pago en una pagada' => [[datosPago('pago_total')], datosPago('anticipo', ['monto' => '1.00'])],
]);

it('rechaza pagos en borrador', function () {
    $borrador = cotizacionParaPagos($this->user, EstadoCotizacion::Borrador);

    $this->actingAs($this->user)->post("/cotizaciones/{$borrador->id}/pagos", datosPago('pago_total'))
        ->assertSessionHasErrorsIn('pago', 'monto');
});

it('valida fecha, forma de pago y monto del anticipo', function (array $cambios, string $campo) {
    ($this->pagar)(datosPago('anticipo', ['monto' => '10.00', ...$cambios]))->assertSessionHasErrorsIn('pago', $campo);
})->with([
    'fecha futura' => [['fecha_pago' => today()->addDays(2)->toDateString()], 'fecha_pago'],
    'forma inexistente' => [['forma_pago' => '07'], 'forma_pago'],
    'monto 0' => [['monto' => '0'], 'monto'],
    'sin monto' => [['monto' => ''], 'monto'],
]);

it('elimina solo el último pago y regresa una pagada a enviada', function () {
    ($this->pagar)(datosPago('anticipo', ['monto' => '500.00']));
    ($this->pagar)(datosPago('saldo'));
    [$anticipo, $saldo] = $this->cotizacion->pagos()->orderBy('id')->get()->all();

    $this->actingAs($this->user)->delete("/cotizaciones/{$this->cotizacion->id}/pagos/{$anticipo->id}")->assertSessionHas('error');
    $this->actingAs($this->user)->delete("/cotizaciones/{$this->cotizacion->id}/pagos/{$saldo->id}")->assertSessionHas('exito');

    expect($this->cotizacion->fresh())
        ->estado->toBe(EstadoCotizacion::Enviada)
        ->saldoPendiente()->toBe('660.00');
});

it('no elimina pagos de una cotización entregada', function () {
    ($this->pagar)(datosPago('pago_total'));
    $this->cotizacion->fresh()->forceFill(['estado' => EstadoCotizacion::ProductoEntregado])->save();

    $this->actingAs($this->user)->delete("/cotizaciones/{$this->cotizacion->id}/pagos/".$this->cotizacion->pagos()->sole()->id)
        ->assertSessionHas('error');

    expect($this->cotizacion->pagos()->count())->toBe(1);
});

it('no elimina el pago de otra cotización por la URL de esta', function () {
    $otra = cotizacionParaPagos($this->user);
    $pago = $otra->pagos()->create(['tipo' => TipoPago::Anticipo, 'fecha_pago' => today(), 'monto' => '10.00', 'forma_pago' => '01']);

    $this->actingAs($this->user)->delete("/cotizaciones/{$this->cotizacion->id}/pagos/{$pago->id}")->assertNotFound();
});

it('muestra en el detalle los botones que corresponden al historial', function () {
    $ver = fn () => $this->actingAs($this->user)->get("/cotizaciones/{$this->cotizacion->id}");

    $ver()->assertSee('Registrar anticipo')->assertSee('Pago total')->assertDontSee('Registrar saldo');

    ($this->pagar)(datosPago('anticipo', ['monto' => '500.00']));
    $ver()->assertDontSee('Registrar anticipo')->assertDontSee('>Pago total', false)->assertSee('Registrar saldo')->assertSee('$660.00');

    ($this->pagar)(datosPago('saldo'));
    $ver()->assertDontSee('Registrar anticipo')->assertDontSee('Registrar saldo')->assertSee('Marcar como entregado');
});
