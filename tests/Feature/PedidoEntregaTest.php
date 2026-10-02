<?php

use App\Enums\EstadoPedido;
use App\Models\Cuenta;
use App\Models\Movimiento;
use App\Models\Pedido;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->pedido = Pedido::factory()->for($this->user)->conLinea(10)->create();
    $this->cuenta = Cuenta::factory()->for($this->user)->create();
    $this->pagar = fn (string $monto) => $this->actingAs($this->user)->post("/pedidos/{$this->pedido->id}/pagos", [
        'cuenta_id' => $this->cuenta->id,
        'fecha_pago' => today('America/Mexico_City')->toDateString(),
        'monto' => $monto,
    ]);
    $this->entregar = fn (array $datos = []) => $this->actingAs($this->user)->post("/pedidos/{$this->pedido->id}/entregar", $datos);
});

it('ya no existe la pantalla de escaneo', function () {
    $this->actingAs($this->user)->get("/pedidos/{$this->pedido->id}/entregar")->assertMethodNotAllowed();
});

it('con saldo, cobra el saldo exacto en la cuenta elegida y entrega', function () {
    ($this->pagar)('500.00');

    ($this->entregar)(['cuenta_id' => $this->cuenta->id, 'monto' => '1.00'])
        ->assertRedirect("/pedidos/{$this->pedido->id}")
        ->assertSessionHas('exito', 'Cobro de $660.00 registrado en '.$this->cuenta->nombre.'. PED-0001 entregado.');

    $pedido = $this->pedido->fresh();
    $cobro = $pedido->pagos()->reorder()->latest('id')->first();

    expect($pedido)
        ->estado->toBe(EstadoPedido::Entregado)
        ->entregado_en->not->toBeNull()
        ->saldoPendiente()->toBe('0.00')
        ->autofactura_token->not->toBeNull()
        ->and($cobro)->monto->toBe('660.00')->registrado_al_entregar->toBeTrue()
        ->and(Movimiento::latest('id')->first()->concepto)->toBe('Saldo al entregar de Pedido PED-0001')
        ->and($this->cuenta->fresh()->saldo_actual)->toBe('1160.00');
});

it('con saldo y sin cuenta no toca nada', function () {
    ($this->entregar)()->assertSessionHasErrors('cuenta_id', null, 'entrega');

    expect($this->pedido->fresh()->estado)->toBe(EstadoPedido::Pendiente)
        ->and(Movimiento::count())->toBe(0);
});

it('sin saldo marca entregado sin registrar pago ni movimiento, y no acepta cuenta', function () {
    ($this->pagar)('1160.00');

    ($this->entregar)(['cuenta_id' => $this->cuenta->id])->assertSessionHasErrors('cuenta_id', null, 'entrega');
    ($this->entregar)()->assertSessionHas('exito', 'PED-0001 entregado.');

    expect($this->pedido->fresh()->estado)->toBe(EstadoPedido::Entregado)
        ->and($this->pedido->pagos()->count())->toBe(1)
        ->and(Movimiento::count())->toBe(1);
});

it('entregar dos veces cobra una sola vez', function () {
    ($this->entregar)(['cuenta_id' => $this->cuenta->id]);
    ($this->entregar)(['cuenta_id' => $this->cuenta->id])->assertSessionHas('error');

    expect($this->pedido->pagos()->count())->toBe(1)
        ->and(Movimiento::count())->toBe(1);
});

it('deshacer una entrega sin cobro regresa el estado de los pagos', function () {
    ($this->pagar)('1160.00');
    ($this->entregar)();

    $this->actingAs($this->user)->post("/pedidos/{$this->pedido->id}/deshacer-entrega")->assertSessionHas('exito');

    expect($this->pedido->fresh())->estado->toBe(EstadoPedido::Pagado)->entregado_en->toBeNull();
});

it('no deshace una entrega que cobró', function () {
    ($this->entregar)(['cuenta_id' => $this->cuenta->id]);

    $this->actingAs($this->user)->post("/pedidos/{$this->pedido->id}/deshacer-entrega")->assertSessionHas('error');

    expect($this->pedido->fresh()->estado)->toBe(EstadoPedido::Entregado)
        ->and(Movimiento::count())->toBe(1);
});

it('no deshace pasados 5 minutos', function () {
    ($this->pagar)('1160.00');
    ($this->entregar)();

    $this->travel(6)->minutes();

    $this->actingAs($this->user)->post("/pedidos/{$this->pedido->id}/deshacer-entrega")->assertSessionHas('error');

    expect($this->pedido->fresh()->estado)->toBe(EstadoPedido::Entregado);
});

it('el detalle de la venta ofrece "Entregado": con confirmación sin saldo, con diálogo de cobro con saldo', function () {
    $url = "/pedidos/{$this->pedido->id}";

    // Saldo pendiente: diálogo que pide la cuenta sin preseleccionar.
    $this->actingAs($this->user)->get($url)
        ->assertSee('Cobrar $1,160.00 y entregar')
        ->assertSee('Elige la cuenta…')
        ->assertSee('name="origen" value="venta"', false);

    // Saldo en cero: un formulario con confirmación, sin cuenta.
    ($this->pagar)('1160.00');
    $this->actingAs($this->user)->get($url)
        ->assertSee('¿Marcar PED-0001 como entregado?')
        ->assertDontSee('Cobrar $', false);

    // Entregado: ofrece deshacer dentro de la ventana, después nada.
    ($this->entregar)();
    $this->actingAs($this->user)->get($url)->assertSee('Deshacer entrega')->assertDontSee('¿Marcar PED-0001 como entregado?');
    $this->travel(6)->minutes();
    $this->actingAs($this->user)->get($url)->assertDontSee('Deshacer entrega');
});

it('sin cuentas activas, el diálogo de entrega con saldo manda a Contabilidad', function () {
    $this->cuenta->update(['activa' => false]);

    $this->actingAs($this->user)->get("/pedidos/{$this->pedido->id}")->assertSee('Da de alta una cuenta en Contabilidad');
});
