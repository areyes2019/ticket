<?php

use App\Enums\EstadoPedido;
use App\Enums\TipoMovimiento;
use App\Models\Cuenta;
use App\Models\Movimiento;
use App\Models\Pedido;
use App\Models\User;
use App\Services\Tesoreria\RegistradorMovimientos;

beforeEach(function () {
    $this->user = User::factory()->create();
    // $1,160.00: 10 × $100 + IVA.
    $this->pedido = Pedido::factory()->for($this->user)->conLinea(10)->create();
    $this->cuenta = Cuenta::factory()->for($this->user)->create();
    $this->pagar = fn (string $monto, ?Cuenta $cuenta = null) => $this->actingAs($this->user)->post("/pedidos/{$this->pedido->id}/pagos", [
        'cuenta_id' => ($cuenta ?? $this->cuenta)->id,
        'fecha_pago' => today('America/Mexico_City')->toDateString(),
        'monto' => $monto,
    ]);
});

it('un anticipo deja el pedido en anticipo y su ingreso mueve la cuenta', function () {
    ($this->pagar)('500.00')->assertSessionHasNoErrors();

    expect($this->pedido->fresh())
        ->estado->toBe(EstadoPedido::Anticipo)
        ->saldoPendiente()->toBe('660.00')
        ->autofactura_token->toBeNull()
        ->and(Movimiento::sole())
        ->tipo->toBe(TipoMovimiento::Ingreso)
        ->monto->toBe('500.00')
        ->concepto->toBe('Pago de Pedido PED-0001')
        ->documentable_type->toBe('pedido_pago')
        ->and($this->cuenta->fresh()->saldo_actual)->toBe('500.00');
});

it('completar el saldo con varios pagos lo deja pagado y genera el enlace de autofactura', function () {
    ($this->pagar)('500.00');
    ($this->pagar)('600.00');
    ($this->pagar)('60.00')->assertSessionHasNoErrors();

    $pedido = $this->pedido->fresh();

    expect($pedido->estado)->toBe(EstadoPedido::Pagado)
        ->and($pedido->autofactura_token)->toHaveLength(64)
        ->and($pedido->autofactura_token)->toMatch('/^[A-Za-z0-9]{64}$/');
});

it('rechaza un pago mayor al saldo y uno en una cuenta inactiva', function () {
    ($this->pagar)('1160.01')->assertSessionHasErrorsIn('pago', 'monto');
    ($this->pagar)('10.00', Cuenta::factory()->for($this->user)->inactiva()->create())->assertSessionHasErrorsIn('pago', 'cuenta_id');

    expect($this->pedido->pagos()->count())->toBe(0);
});

it('rechaza la cuenta de otro usuario', function () {
    ($this->pagar)('10.00', Cuenta::factory()->create())->assertSessionHasErrorsIn('pago', 'cuenta_id');
});

it('eliminar cualquier pago borra su ingreso y regresa el estado', function () {
    ($this->pagar)('1000.00');
    ($this->pagar)('160.00');
    $primero = $this->pedido->pagos()->orderBy('id')->first();

    $this->actingAs($this->user)->delete("/pedidos/{$this->pedido->id}/pagos/{$primero->id}")->assertSessionHas('exito');

    expect($this->pedido->fresh())
        ->estado->toBe(EstadoPedido::Anticipo)
        ->totalPagado()->toBe('160.00')
        ->and(Movimiento::count())->toBe(1)
        ->and($this->cuenta->fresh()->saldo_actual)->toBe('160.00');
});

it('no elimina un pago si la cuenta quedaría en negativo', function () {
    ($this->pagar)('500.00');
    $otra = Cuenta::factory()->for($this->user)->create();
    app(RegistradorMovimientos::class)->transferir($this->cuenta, $otra, '500.00', today()->toDateString(), 'Retiro');

    $this->actingAs($this->user)->delete("/pedidos/{$this->pedido->id}/pagos/{$this->pedido->pagos()->first()->id}")->assertSessionHas('error');

    expect($this->pedido->pagos()->count())->toBe(1);
});

it('la utilidad del pedido aparece en Contabilidad: completa con catálogo y parcial con una línea libre', function () {
    $articulo = articuloFacturable($this->user);
    marcarExistencia($articulo, 10);

    $this->actingAs($this->user)->post('/pedidos', datosPedido([lineaPedido($articulo, 1, '200.00')]));
    $soloCatalogo = Pedido::latest('id')->first();
    $this->actingAs($this->user)->post('/pedidos', datosPedido([lineaPedido($articulo, 1, '200.00'), lineaPedido(null, 1, '50.00')]));
    $conLibre = Pedido::latest('id')->first();

    $costo = (float) $articulo->costo_con_descuento;

    expect($soloCatalogo->utilidadVenta())->toBe(['utilidad' => number_format(200 - $costo, 2, '.', ''), 'parcial' => false])
        ->and($conLibre->utilidadVenta()['parcial'])->toBeTrue();

    $this->actingAs($this->user)->post("/pedidos/{$conLibre->id}/pagos", ['cuenta_id' => $this->cuenta->id, 'fecha_pago' => today('America/Mexico_City')->toDateString(), 'monto' => '10.00']);

    $this->actingAs($this->user)->get('/tesoreria/movimientos')
        ->assertSee($conLibre->folio_formateado)
        ->assertSee('Parcial');
});
