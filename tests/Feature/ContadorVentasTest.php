<?php

use App\Enums\EstadoCotizacion;
use App\Enums\EstadoPedido;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\Pedido;
use App\Models\User;
use App\Services\Ventas\ContadorVentas;
use Illuminate\Support\Carbon;

beforeEach(function () {
    // 20 de octubre, 23:00 en el negocio: en UTC ya es 21 (regla 8).
    Carbon::setTestNow(Carbon::parse('2026-10-20 23:00', 'America/Mexico_City'));

    $this->user = User::factory()->create();
    $this->cuenta = Cuenta::factory()->for($this->user)->create();
    $this->hoy = '2026-10-20';
    $this->ayer = '2026-10-19';
    $this->contar = fn (?string $dia = null) => app(ContadorVentas::class)->delDia($this->user, Carbon::parse($dia ?? $this->hoy));
    $this->pagarPedido = fn (Pedido $pedido, string $fecha) => $pedido->pagos()->create(['cuenta_id' => $this->cuenta->id, 'fecha_pago' => $fecha, 'monto' => '100.00']);
    $this->pagarCotizacion = fn (Cotizacion $cotizacion, string $fecha, string $tipo = 'anticipo') => $cotizacion->pagos()->create(['tipo' => $tipo, 'fecha_pago' => $fecha, 'monto' => '100.00', 'cuenta_id' => $this->cuenta->id]);
    $this->cotizacion = fn () => Cotizacion::factory()->for($this->user)->enEstado(EstadoCotizacion::Enviada)->create(['cliente_id' => Cliente::factory()->for($this->user)]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('una venta de mostrador cuenta una vez aunque tenga varios pagos hoy', function () {
    $pedido = Pedido::factory()->for($this->user)->create();
    ($this->pagarPedido)($pedido, $this->hoy);

    expect(($this->contar)())->toBe(1);

    ($this->pagarPedido)($pedido, $this->hoy);

    expect(($this->contar)())->toBe(1);
});

it('cuenta el día del primer pago: anticipo ayer y saldo hoy no suma hoy', function () {
    $pedido = Pedido::factory()->for($this->user)->create();
    ($this->pagarPedido)($pedido, $this->ayer);
    ($this->pagarPedido)($pedido, $this->hoy);

    expect(($this->contar)())->toBe(0)
        ->and(($this->contar)($this->ayer))->toBe(1);
});

it('una cotización sin venta con anticipo cuenta, y la que tiene venta cuenta solo como su venta', function () {
    $sinVenta = ($this->cotizacion)();
    ($this->pagarCotizacion)($sinVenta, $this->hoy);

    expect(($this->contar)())->toBe(1);

    $conVenta = ($this->cotizacion)();
    ($this->pagarCotizacion)($conVenta, $this->hoy);
    Pedido::factory()->for($this->user)->create(['cotizacion_id' => $conVenta->id]);

    expect(($this->contar)())->toBe(2);
});

it('una venta con pagos en su cotización y en ella cuenta el día del más antiguo', function () {
    $cotizacion = ($this->cotizacion)();
    ($this->pagarCotizacion)($cotizacion, $this->ayer);
    $pedido = Pedido::factory()->for($this->user)->create(['cotizacion_id' => $cotizacion->id]);
    ($this->pagarPedido)($pedido, $this->hoy);

    expect(($this->contar)())->toBe(0)
        ->and(($this->contar)($this->ayer))->toBe(1);
});

it('no cuenta lo reembolsado ni lo que no tiene pagos', function () {
    $reembolsada = Pedido::factory()->for($this->user)->enEstado(EstadoPedido::Reembolsado)->create();
    ($this->pagarPedido)($reembolsada, $this->hoy);
    $cotizacion = Cotizacion::factory()->for($this->user)->enEstado(EstadoCotizacion::Reembolsada)->create(['cliente_id' => Cliente::factory()->for($this->user)]);
    ($this->pagarCotizacion)($cotizacion, $this->hoy);
    Pedido::factory()->for($this->user)->create();
    ($this->cotizacion)();

    expect(($this->contar)())->toBe(0);
});

it('borrar pagos mueve el día de la venta o la saca', function () {
    $pedido = Pedido::factory()->for($this->user)->create();
    $primero = ($this->pagarPedido)($pedido, $this->ayer);
    ($this->pagarPedido)($pedido, $this->hoy);

    $primero->delete();

    expect(($this->contar)())->toBe(1);

    $pedido->pagos()->delete();

    expect(($this->contar)())->toBe(0);
});

it('no cuenta las ventas de otra cuenta', function () {
    $ajeno = Pedido::factory()->create();
    $ajeno->pagos()->create(['cuenta_id' => Cuenta::factory()->for($ajeno->user)->create()->id, 'fecha_pago' => $this->hoy, 'monto' => '100.00']);

    expect(($this->contar)())->toBe(0);
});

it('hoy es la fecha del negocio aunque en UTC ya sea mañana', function () {
    ($this->pagarPedido)(Pedido::factory()->for($this->user)->create(), $this->hoy);

    expect(app(ContadorVentas::class)->hoy($this->user))->toBe(1);
});

it('la barra muestra el contador con su texto, en cero y con 99+', function () {
    $this->actingAs($this->user)->get('/dashboard')
        ->assertOk()
        ->assertSee('title="Ventas de hoy"', false)
        ->assertSee('aria-label="Ventas de hoy: 0"', false)
        ->assertSee('ventas-hoy-cero', false);

    Pedido::factory()->for($this->user)->count(100)->create()
        ->each(fn (Pedido $pedido) => ($this->pagarPedido)($pedido, $this->hoy));

    $this->actingAs($this->user)->get('/dashboard')
        ->assertSee('aria-label="Ventas de hoy: 100"', false)
        ->assertSee('99+')
        ->assertDontSee('ventas-hoy-cero', false);
});

it('no aparece sin sesión ni en el mostrador', function () {
    $this->get('/login')->assertDontSee('Ventas de hoy');

    $this->actingAs($this->user)->get('/mostrador')->assertOk()->assertDontSee('Ventas de hoy');
});
