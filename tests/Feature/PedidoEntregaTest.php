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

it('sin sesión, el escaneo manda al login y regresa al pedido', function () {
    $url = "/pedidos/{$this->pedido->id}/entregar";

    $this->get($url)->assertRedirect('/login');
    $this->post('/login', ['email' => $this->user->email, 'password' => 'password'])->assertRedirect($url);
});

it('con saldo, cobra el saldo exacto en la cuenta elegida y entrega', function () {
    ($this->pagar)('500.00');

    ($this->entregar)(['cuenta_id' => $this->cuenta->id, 'monto' => '1.00'])
        ->assertRedirect("/pedidos/{$this->pedido->id}/entregar")
        ->assertSessionHas('exito');

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
    ($this->entregar)()->assertSessionHasErrors('cuenta_id');

    expect($this->pedido->fresh()->estado)->toBe(EstadoPedido::Pendiente)
        ->and(Movimiento::count())->toBe(0);
});

it('sin saldo marca entregado sin registrar pago ni movimiento, y no acepta cuenta', function () {
    ($this->pagar)('1160.00');

    ($this->entregar)(['cuenta_id' => $this->cuenta->id])->assertSessionHasErrors('cuenta_id');
    ($this->entregar)()->assertSessionHas('entrega_sin_cobro', $this->pedido->id);

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

it('pinta el camino que corresponde al estado', function () {
    $url = "/pedidos/{$this->pedido->id}/entregar";

    // Saldo pendiente: pide cuenta, sin preseleccionar, y no toca nada.
    $this->actingAs($this->user)->get($url)
        ->assertSee('Cobrar y entregar $1,160.00')
        ->assertSee('Elige la cuenta…')
        ->assertDontSee('selected', false)
        ->assertDontSee('data-enviar-al-cargar', false);

    expect($this->pedido->fresh()->estado)->toBe(EstadoPedido::Pendiente);

    // Saldo en cero: se envía solo al cargar.
    ($this->pagar)('1160.00');
    $this->actingAs($this->user)->get($url)->assertSee('data-enviar-al-cargar', false)->assertDontSee('cuenta_id', false);

    // Ya entregado: solo informa (el Deshacer solo justo después de entregar).
    ($this->entregar)();
    $this->actingAs($this->user)->withSession(['entrega_sin_cobro' => $this->pedido->id])->get($url)->assertSee('Entregado el')->assertSee('Deshacer');
    $this->actingAs($this->user)->get($url)->assertSee('Entregado el')->assertDontSee('Deshacer')->assertDontSee('<form method="POST" action="'.route('pedidos.entregar.store', $this->pedido), false);
});

it('sin cuentas activas, la entrega con saldo manda a Contabilidad', function () {
    $this->cuenta->update(['activa' => false]);

    $this->actingAs($this->user)->get("/pedidos/{$this->pedido->id}/entregar")->assertSee('Da de alta una cuenta en Contabilidad');
});
