<?php

use App\Enums\EstadoPedido;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\Pedido;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->articulo = articuloFacturable($this->user);
    marcarExistencia($this->articulo, 10);
});

it('pide sesión en todas las rutas de pedidos y configuración', function (string $metodo, string $url) {
    $pedido = Pedido::factory()->for($this->user)->conLinea()->create();

    $this->call($metodo, str_replace('{id}', (string) $pedido->id, $url))->assertRedirect('/login');
})->with([
    ['GET', '/pedidos'],
    ['GET', '/pedidos/crear'],
    ['POST', '/pedidos'],
    ['GET', '/pedidos/{id}'],
    ['GET', '/pedidos/{id}/editar'],
    ['PUT', '/pedidos/{id}'],
    ['DELETE', '/pedidos/{id}'],
    ['GET', '/pedidos/{id}/ticket'],
    ['GET', '/pedidos/{id}/etiqueta'],
    ['POST', '/pedidos/{id}/entregar'],
    ['POST', '/pedidos/{id}/deshacer-entrega'],
    ['POST', '/pedidos/{id}/pagos'],
    ['GET', '/pedidos/cliente-por-telefono'],
    ['GET', '/configuracion'],
    ['PUT', '/configuracion'],
]);

it('un pedido ajeno responde 404 en todas sus acciones', function (string $metodo, string $url) {
    $ajeno = Pedido::factory()->conLinea()->create();

    $this->actingAs($this->user)->call($metodo, str_replace('{id}', (string) $ajeno->id, $url))->assertNotFound();
})->with([
    ['GET', '/pedidos/{id}'],
    ['GET', '/pedidos/{id}/editar'],
    ['DELETE', '/pedidos/{id}'],
    ['GET', '/pedidos/{id}/ticket'],
    ['GET', '/pedidos/{id}/etiqueta'],
    ['POST', '/pedidos/{id}/entregar'],
    ['POST', '/pedidos/{id}/deshacer-entrega'],
    ['POST', '/pedidos/{id}/pagos'],
]);

it('crea el pedido con líneas de catálogo y libres, totales y costo calculados en el servidor', function () {
    $this->actingAs($this->user)->post('/pedidos', datosPedido([
        lineaPedido($this->articulo, 2, '150.00'),
        lineaPedido(null, 1, '50.00'),
    ], ['total' => '1.00', 'subtotal' => '1.00']))->assertSessionHasNoErrors();

    $pedido = Pedido::with('lineas')->sole();

    expect($pedido)
        ->estado->toBe(EstadoPedido::Pendiente)
        ->folio->toBe(1)
        ->cliente_telefono->toBe('+524491234567')
        ->subtotal->toBe('350.00')
        ->total_iva_16->toBe('56.00')
        ->total->toBe('406.00')
        ->and($pedido->lineas[0]->costo_unitario)->toBe($this->articulo->costo_con_descuento)
        ->and($pedido->lineas[1]->articulo_id)->toBeNull()
        ->and($pedido->lineas[1]->costo_unitario)->toBeNull();
});

it('no toca el catálogo de clientes al crear un pedido', function () {
    $this->actingAs($this->user)->post('/pedidos', datosPedido([lineaPedido(null)]));

    expect(Cliente::count())->toBe(0);
});

it('valida nombre, teléfono y al menos una línea', function () {
    $this->actingAs($this->user)->post('/pedidos', datosPedido([], ['cliente_nombre' => '', 'cliente_telefono' => '123']))
        ->assertSessionHasErrors(['cliente_nombre', 'cliente_telefono', 'lineas']);

    expect(Pedido::count())->toBe(0);
});

it('numera por usuario, independiente de cotizaciones, y no reutiliza folios', function () {
    Cotizacion::factory()->for(Cliente::factory()->for($this->user))->create(['user_id' => $this->user->id]);
    $otro = User::factory()->create();

    $this->actingAs($this->user)->post('/pedidos', datosPedido([lineaPedido(null)]));
    $this->actingAs($this->user)->post('/pedidos', datosPedido([lineaPedido(null)]));
    Pedido::where('folio', 2)->first()->delete();
    $this->actingAs($this->user)->post('/pedidos', datosPedido([lineaPedido(null)]));
    $this->actingAs($otro)->post('/pedidos', datosPedido([lineaPedido(null)]));

    expect($this->user->pedidos()->pluck('folio')->all())->toBe([1, 3])
        ->and($otro->pedidos()->value('folio'))->toBe(1)
        ->and(Pedido::where('folio', 3)->first()->folio_formateado)->toBe('PED-0003');
});

it('no edita un pedido pagado ni uno entregado', function (EstadoPedido $estado) {
    $pedido = Pedido::factory()->for($this->user)->conLinea()->enEstado($estado)->create();

    $this->actingAs($this->user)->get("/pedidos/{$pedido->id}/editar")->assertForbidden();
    $this->actingAs($this->user)->put("/pedidos/{$pedido->id}", datosPedido([lineaPedido(null)]))->assertForbidden();
})->with([EstadoPedido::Pagado, EstadoPedido::Entregado]);

it('al editar con pagos, el total no puede quedar por debajo de lo pagado', function () {
    $pedido = Pedido::factory()->for($this->user)->conLinea(1, '100.00')->create();
    $cuenta = Cuenta::factory()->for($this->user)->create();
    $this->actingAs($this->user)->post("/pedidos/{$pedido->id}/pagos", ['cuenta_id' => $cuenta->id, 'fecha_pago' => today('America/Mexico_City')->toDateString(), 'monto' => '100.00']);

    $this->actingAs($this->user)->put("/pedidos/{$pedido->id}", datosPedido([lineaPedido(null, 1, '50.00')]))
        ->assertSessionHasErrors('lineas');
});

it('borra un pedido pendiente sin pagos y rechaza el que tiene pagos', function () {
    $libre = Pedido::factory()->for($this->user)->conLinea()->create();
    $conPago = Pedido::factory()->for($this->user)->conLinea()->create();
    $cuenta = Cuenta::factory()->for($this->user)->create();
    $this->actingAs($this->user)->post("/pedidos/{$conPago->id}/pagos", ['cuenta_id' => $cuenta->id, 'fecha_pago' => today('America/Mexico_City')->toDateString(), 'monto' => '10.00']);

    $this->actingAs($this->user)->delete("/pedidos/{$libre->id}")->assertRedirect('/pedidos');
    $this->actingAs($this->user)->delete("/pedidos/{$conPago->id}")->assertSessionHas('error');

    expect(Pedido::find($libre->id))->toBeNull()
        ->and(Pedido::find($conPago->id))->not->toBeNull();
});

it('sugiere nombre y correo de la venta anterior con el mismo teléfono, sin datos ajenos', function () {
    Pedido::factory()->for($this->user)->create(['cliente_telefono' => '+524491234567', 'cliente_nombre' => 'Ana Ruiz', 'cliente_correo' => 'ana@example.com']);
    Pedido::factory()->create(['cliente_telefono' => '+524499999999', 'cliente_nombre' => 'Ajena']);

    $this->actingAs($this->user)->getJson('/pedidos/cliente-por-telefono?telefono=449-123-4567')
        ->assertExactJson(['nombre' => 'Ana Ruiz', 'correo' => 'ana@example.com']);
    $this->actingAs($this->user)->getJson('/pedidos/cliente-por-telefono?telefono=4499999999')->assertExactJson([]);
    $this->actingAs($this->user)->getJson('/pedidos/cliente-por-telefono?telefono=449')->assertExactJson([]);
});

it('filtra el listado por folio, cliente, teléfono y estado', function () {
    Pedido::factory()->for($this->user)->create(['cliente_nombre' => 'Ana Ruiz', 'cliente_telefono' => '+524491111111']);
    Pedido::factory()->for($this->user)->enEstado(EstadoPedido::Pagado)->create(['cliente_nombre' => 'Beto Lara', 'cliente_telefono' => '+524492222222']);

    $this->actingAs($this->user)->get('/pedidos?folio=PED-0002')->assertSee('Beto Lara')->assertDontSee('Ana Ruiz');
    $this->actingAs($this->user)->get('/pedidos?cliente=ana')->assertSee('Ana Ruiz')->assertDontSee('Beto Lara');
    $this->actingAs($this->user)->get('/pedidos?telefono=2222')->assertSee('Beto Lara')->assertDontSee('Ana Ruiz');
    $this->actingAs($this->user)->get('/pedidos?estado=pagado')->assertSee('Beto Lara')->assertDontSee('Ana Ruiz');
    $this->actingAs($this->user)->get('/pedidos/buscar?estado=pendiente', cabecerasAjax())->assertSee('Ana Ruiz')->assertDontSee('Beto Lara')->assertDontSee('<html', false);
});

it('marca en el listado el pedido cuyo cliente no pudo facturar', function () {
    Pedido::factory()->for($this->user)->create()->forceFill(['autofactura_error' => 'RFC no inscrito'])->save();

    $this->actingAs($this->user)->get('/pedidos')->assertSee('El cliente intentó facturar y no pudo: RFC no inscrito', false);
});

it('muestra el detalle con la vista previa del ticket pero sin compartir antes del primer pago', function () {
    $pedido = Pedido::factory()->for($this->user)->conLinea()->create();

    $this->actingAs($this->user)->get("/pedidos/{$pedido->id}")
        ->assertOk()
        ->assertSee(route('pedidos.ticket', $pedido))
        ->assertDontSee('Compartir ticket')
        ->assertDontSee('Avisar que está listo');
});

it('muestra los formularios de alta y edición', function () {
    $pedido = Pedido::factory()->for($this->user)->conLinea()->create();

    $this->actingAs($this->user)->get('/pedidos/crear')->assertOk()->assertSee('Agregar línea libre')->assertSee(route('pedidos.cliente-por-telefono'));
    $this->actingAs($this->user)->get("/pedidos/{$pedido->id}/editar")->assertOk()->assertSee('Sello de prueba')->assertSee($pedido->telefono_legible);
});
