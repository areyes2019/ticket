<?php

use App\Enums\EstadoCotizacion;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPago;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\Movimiento;
use App\Models\User;
use App\Services\Tesoreria\RegistradorMovimientos;

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
 * Sin cuenta: la pone $this->pagar (los datasets no tienen base de datos).
 * La fecha va en la zona del negocio (la que valida "no futura"); se escribe
 * literal porque los datasets llaman a esta función antes de que exista config().
 *
 * @param  array<string, mixed>  $cambios
 * @return array<string, mixed>
 */
function datosPago(string $tipo, array $cambios = []): array
{
    return ['tipo' => $tipo, 'fecha_pago' => today('America/Mexico_City')->toDateString(), ...$cambios];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->cotizacion = cotizacionParaPagos($this->user);
    $this->cuenta = Cuenta::factory()->for($this->user)->create();
    $this->pagar = fn (array $datos) => $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/pagos", ['cuenta_id' => $this->cuenta->id, ...$datos]);
});

it('registrado desde la bandeja, regresa a ella con la misma cotización abierta', function () {
    $bandeja = route('cotizaciones.index', ['estado' => 'enviada', 'cotizacion' => $this->cotizacion->id]);

    $this->actingAs($this->user)->from($bandeja)->post("/cotizaciones/{$this->cotizacion->id}/pagos", datosPago('pago_total', ['origen' => 'bandeja', 'cuenta_id' => $this->cuenta->id]))
        ->assertRedirect($bandeja)
        ->assertSessionHas('exito');
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

    $this->actingAs($this->user)->post("/cotizaciones/{$borrador->id}/pagos", datosPago('pago_total', ['cuenta_id' => $this->cuenta->id]))
        ->assertSessionHasErrorsIn('pago', 'monto');
});

it('valida fecha y monto del anticipo', function (array $cambios, string $campo) {
    ($this->pagar)(datosPago('anticipo', ['monto' => '10.00', ...$cambios]))->assertSessionHasErrorsIn('pago', $campo);
})->with([
    'fecha futura' => [['fecha_pago' => today()->addDays(2)->toDateString()], 'fecha_pago'],
    'sin cuenta' => [['cuenta_id' => ''], 'cuenta_id'],
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
    $pago = $otra->pagos()->create(['tipo' => TipoPago::Anticipo, 'fecha_pago' => today(), 'monto' => '10.00', 'cuenta_id' => $this->cuenta->id]);

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

describe('tesorería', function () {
    it('cada tipo de pago crea su ingreso en la cuenta elegida', function () {
        ($this->pagar)(datosPago('anticipo', ['monto' => '500.00']));
        ($this->pagar)(datosPago('saldo'));

        $folio = $this->cotizacion->folio_formateado;
        $movimientos = Movimiento::orderBy('id')->get();

        expect($movimientos)->toHaveCount(2)
            ->and($movimientos->pluck('concepto')->all())->toBe(["Anticipo de Cotización {$folio}", "Saldo de Cotización {$folio}"])
            ->and($movimientos->pluck('monto')->all())->toBe(['500.00', '660.00'])
            ->and($movimientos->every(fn (Movimiento $m) => $m->tipo === TipoMovimiento::Ingreso && $m->cuenta_id === $this->cuenta->id && $m->esAutomatico()))->toBeTrue()
            ->and($movimientos[0]->documentable->is($this->cotizacion->pagos()->orderBy('id')->first()))->toBeTrue()
            ->and($movimientos[0]->fecha->toDateString())->toBe(today('America/Mexico_City')->toDateString())
            ->and($this->cuenta->fresh()->saldo_actual)->toBe('1160.00');
    });

    it('el pago total usa su propio concepto', function () {
        ($this->pagar)(datosPago('pago_total'));

        expect(Movimiento::sole()->concepto)->toBe('Pago total de Cotización '.$this->cotizacion->folio_formateado);
    });

    it('rechaza una cuenta inactiva o ajena sin registrar el pago', function () {
        $inactiva = Cuenta::factory()->for($this->user)->inactiva()->create(['nombre' => 'Vieja']);

        ($this->pagar)(datosPago('pago_total', ['cuenta_id' => $inactiva->id]))
            ->assertSessionHasErrorsIn('pago', ['cuenta_id' => 'La cuenta Vieja está inactiva y no admite movimientos.']);
        ($this->pagar)(datosPago('pago_total', ['cuenta_id' => Cuenta::factory()->create()->id]))
            ->assertSessionHasErrorsIn('pago', 'cuenta_id');

        expect($this->cotizacion->pagos()->count())->toBe(0)
            ->and(Movimiento::count())->toBe(0);
    });

    it('eliminar el último pago se lleva su ingreso y recalcula el saldo', function () {
        ($this->pagar)(datosPago('anticipo', ['monto' => '500.00']));
        ($this->pagar)(datosPago('saldo'));
        $saldo = $this->cotizacion->pagos()->reorder()->latest('id')->first();

        $this->actingAs($this->user)->delete("/cotizaciones/{$this->cotizacion->id}/pagos/{$saldo->id}")->assertSessionHas('exito');

        expect(Movimiento::sole()->monto)->toBe('500.00')
            ->and($this->cuenta->fresh()->saldo_actual)->toBe('500.00')
            ->and($this->cotizacion->fresh()->estado)->toBe(EstadoCotizacion::Enviada);
    });

    it('elimina el pago aunque la cuenta ya esté inactiva', function () {
        ($this->pagar)(datosPago('pago_total'));
        $this->cuenta->update(['activa' => false]);

        $this->actingAs($this->user)->delete("/cotizaciones/{$this->cotizacion->id}/pagos/".$this->cotizacion->pagos()->sole()->id)
            ->assertSessionHas('exito');

        expect(Movimiento::count())->toBe(0)
            ->and($this->cuenta->fresh()->saldo_actual)->toBe('0.00');
    });

    it('no elimina el pago si la cuenta quedaría en negativo', function () {
        ($this->pagar)(datosPago('pago_total'));
        app(RegistradorMovimientos::class)->registrar($this->cuenta, TipoMovimiento::Egreso, '1000', today('America/Mexico_City')->toDateString(), 'Compra');

        $this->actingAs($this->user)->delete("/cotizaciones/{$this->cotizacion->id}/pagos/".$this->cotizacion->pagos()->sole()->id)
            ->assertSessionHas('error', "No se puede eliminar el pago: la cuenta {$this->cuenta->nombre} quedaría con saldo negativo.");

        expect($this->cotizacion->pagos()->count())->toBe(1)
            ->and(Movimiento::count())->toBe(2)
            ->and($this->cotizacion->fresh()->estado)->toBe(EstadoCotizacion::Pagada);
    });

    it('el movimiento automático no se edita ni se elimina desde Tesorería', function () {
        ($this->pagar)(datosPago('pago_total'));
        $movimiento = Movimiento::sole();
        $folio = $this->cotizacion->folio_formateado;

        $this->actingAs($this->user)->get("/tesoreria/movimientos/{$movimiento->id}/editar")->assertForbidden();
        $this->actingAs($this->user)->delete("/tesoreria/movimientos/{$movimiento->id}")->assertForbidden();

        $this->actingAs($this->user)->get('/tesoreria/movimientos')
            ->assertSee(route('cotizaciones.show', $this->cotizacion), false)
            ->assertSee('Automático')
            ->assertSee("Se corrige desde {$folio}");

        expect(Movimiento::count())->toBe(1);
    });

    it('el detalle muestra la cuenta del pago y pide cuenta en el diálogo', function () {
        ($this->pagar)(datosPago('anticipo', ['monto' => '100.00']));

        $this->actingAs($this->user)->get("/cotizaciones/{$this->cotizacion->id}")
            ->assertSee('<th>Cuenta</th>', false)
            ->assertSee('<td>'.e($this->cuenta->nombre).'</td>', false)
            ->assertSee('name="cuenta_id"', false)
            ->assertDontSee('name="forma_pago"', false)
            ->assertSee('También se eliminará su ingreso en Contabilidad');
    });

    it('sin cuentas activas el diálogo pide crear una', function () {
        $this->cuenta->update(['activa' => false]);

        $this->actingAs($this->user)->get("/cotizaciones/{$this->cotizacion->id}")
            ->assertSee('Para registrar pagos, primero crea una cuenta en Contabilidad')
            ->assertDontSee('name="cuenta_id"', false);
    });

    it('crear una cotización no genera movimientos', function () {
        cotizacionParaPagos($this->user, EstadoCotizacion::Borrador);

        expect(Movimiento::count())->toBe(0);
    });
});
