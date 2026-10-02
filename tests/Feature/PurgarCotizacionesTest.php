<?php

use App\Enums\EstadoCotizacion;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionLinea;
use App\Models\Cuenta;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->cliente = Cliente::factory()->for($this->user)->create();
    $this->crear = fn (EstadoCotizacion $estado, int $diasSinMovimiento) => Cotizacion::factory()
        ->for($this->cliente)
        ->conLinea()
        ->enEstado($estado)
        ->create(['user_id' => $this->user->id])
        ->forceFill(['updated_at' => now()->subDays($diasSinMovimiento)])
        ->saveQuietly();
});

it('borra las borrador y enviadas sin pagos con más de 30 días sin movimiento', function () {
    ($this->crear)(EstadoCotizacion::Borrador, 31);
    ($this->crear)(EstadoCotizacion::Enviada, 45);
    ($this->crear)(EstadoCotizacion::Borrador, 29);
    ($this->crear)(EstadoCotizacion::Pagada, 90);
    ($this->crear)(EstadoCotizacion::ProductoEntregado, 90);

    $conPago = Cotizacion::factory()->for($this->cliente)->conLinea()->enEstado(EstadoCotizacion::Enviada)->create(['user_id' => $this->user->id]);
    $conPago->pagos()->create(['tipo' => 'anticipo', 'fecha_pago' => today(), 'monto' => '10.00', 'cuenta_id' => Cuenta::factory()->for($this->user)->create()->id]);
    $conPago->forceFill(['updated_at' => now()->subDays(60)])->saveQuietly();

    $this->artisan('cotizaciones:purgar-vencidas')
        ->expectsOutput('Se eliminaron 2 cotizaciones vencidas.')
        ->assertSuccessful();

    expect(Cotizacion::count())->toBe(4)
        ->and(CotizacionLinea::count())->toBe(4);

    $this->artisan('cotizaciones:purgar-vencidas')->expectsOutput('Se eliminaron 0 cotizaciones vencidas.');
});

it('está agendado todos los días', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('cotizaciones:purgar-vencidas');
});

it('calcula la fecha de caducidad y avisa desde 7 días antes', function () {
    $this->travelTo(now()->setTimezone('America/Mexico_City')->setTime(12, 0));

    $cotizacion = Cotizacion::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);

    expect($cotizacion->diasParaCaducar())->toBe(30)
        ->and($cotizacion->mostrarAvisoCaducidad())->toBeFalse();

    $this->travel(23)->days();
    expect($cotizacion->diasParaCaducar())->toBe(7)
        ->and($cotizacion->mostrarAvisoCaducidad())->toBeTrue()
        ->and($cotizacion->textoCaducidad())->toBe('Se elimina en 7 días');

    // Carpeta "Todas": 23 días después la cotización puede ya no ser "de este mes".
    $this->actingAs($this->user)->get('/cotizaciones?periodo=todas')->assertSee('Se elimina en 7 días');
    $this->actingAs($this->user)->get("/cotizaciones/{$cotizacion->id}")->assertSee('Se eliminará automáticamente');

    $this->travel(7)->days();
    expect($cotizacion->textoCaducidad())->toBe('Se elimina hoy');
});

it('no avisa en las que no caducan', function (EstadoCotizacion $estado) {
    $cotizacion = Cotizacion::factory()->for($this->cliente)->conLinea()->enEstado($estado)->create(['user_id' => $this->user->id]);

    $this->travel(29)->days();

    expect($cotizacion->caducaEl())->toBeNull()
        ->and($cotizacion->mostrarAvisoCaducidad())->toBeFalse();
})->with([EstadoCotizacion::Pagada, EstadoCotizacion::ProductoEntregado]);

it('editar reinicia el plazo y el aviso desaparece', function () {
    $cotizacion = Cotizacion::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);
    $this->travel(25)->days();

    $this->actingAs($this->user)->put("/cotizaciones/{$cotizacion->id}", [
        'cliente_id' => $this->cliente->id,
        'lineas' => [['cantidad' => '1', 'descripcion' => 'Sello de prueba', 'precio_unitario' => '100.00', 'tasa_iva' => '16']],
    ])->assertSessionHasNoErrors();

    expect($cotizacion->fresh()->diasParaCaducar())->toBe(30)
        ->and($cotizacion->fresh()->mostrarAvisoCaducidad())->toBeFalse();
});
