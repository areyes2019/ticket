<?php

use App\Models\Cuenta;
use App\Models\OrdenTrabajo;
use App\Models\Pedido;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->cuenta = Cuenta::factory()->for($this->user)->create();

    $this->pagar = fn (Pedido $pedido, string $monto) => $this->actingAs($this->user)->post("/pedidos/{$pedido->id}/pagos", [
        'cuenta_id' => $this->cuenta->id,
        'fecha_pago' => today('America/Mexico_City')->toDateString(),
        'monto' => $monto,
    ]);

    // Venta de $116.00 (una línea libre, modelo P-1) con su orden en el estado indicado.
    $this->venta = function (string $estado = 'en_proceso', array $atributos = [], ?User $dueno = null): Pedido {
        $dueno ??= $this->user;
        $pedido = Pedido::factory()->for($dueno)->conLinea()->create($atributos);
        $orden = new OrdenTrabajo;
        $orden->forceFill(['user_id' => $dueno->id, 'pedido_id' => $pedido->id, 'estado' => $estado])->save();

        return $pedido;
    };

    $this->agregarLinea = fn (Pedido $pedido, ?string $modelo, int $orden) => $pedido->lineas()->create([
        'cantidad' => 1, 'precio_unitario' => '0.00', 'tasa_iva' => '16', 'importe' => '0.00', 'iva_importe' => '0.00',
        'orden' => $orden, 'descripcion' => 'Otra línea', 'modelo' => $modelo,
    ]);
});

it('pide sesión', function () {
    $this->get('/pedidos/produccion/etiquetas')->assertRedirect('/login');
});

it('hace una etiqueta por cada orden en proceso del usuario, por folio', function () {
    // La de folio menor se crea después: el orden es por folio, no por id.
    $segunda = ($this->venta)('en_proceso', ['cliente_nombre' => 'Cliente segundo', 'folio' => 50]);
    $primera = ($this->venta)('en_proceso', ['cliente_nombre' => 'Cliente primero', 'folio' => 7]);
    ($this->venta)('en_dibujo', ['cliente_nombre' => 'Cliente en dibujo']);
    ($this->venta)('terminado', ['cliente_nombre' => 'Cliente terminado']);
    ($this->venta)('en_proceso', ['cliente_nombre' => 'Cliente ajeno'], User::factory()->create());

    $respuesta = $this->actingAs($this->user)->get('/pedidos/produccion/etiquetas')->assertOk()
        ->assertSeeInOrder([$primera->folio_formateado, 'Cliente primero', $segunda->folio_formateado, 'Cliente segundo'])
        ->assertSee($segunda->telefono_legible)
        ->assertSee('P-1')
        ->assertSee('2 órdenes en proceso')
        ->assertSee('3 × 8 = 24 por hoja · 1 hoja')
        ->assertSee('js/etiquetas-produccion.js')
        ->assertDontSee('imprimir-al-cargar.js')
        ->assertDontSee('Cliente en dibujo')
        ->assertDontSee('Cliente terminado')
        ->assertDontSee('Cliente ajeno');

    expect(substr_count($respuesta->getContent(), 'class="planilla-etiqueta"'))->toBe(2);
});

it('muestra el saldo o PAGADO', function () {
    $conSaldo = ($this->venta)('en_proceso', ['cliente_nombre' => 'Con saldo']);
    $pagada = ($this->venta)('en_proceso', ['cliente_nombre' => 'Pagada']);
    ($this->pagar)($conSaldo, '16.00');
    ($this->pagar)($pagada, '116.00');

    $this->actingAs($this->user)->get('/pedidos/produccion/etiquetas')->assertOk()
        ->assertSeeInOrder(['Con saldo', 'SALDO: $100.00', 'Pagada', 'PAGADO']);
});

it('junta los modelos sin cantidad ni repetidos, y sin modelo muestra una raya', function () {
    $varios = ($this->venta)('en_proceso', ['cliente_nombre' => 'Varios']);
    ($this->agregarLinea)($varios, 'MD6040', 2);
    ($this->agregarLinea)($varios, 'P-1', 3);
    ($this->agregarLinea)($varios, null, 4);

    $sinModelo = ($this->venta)('en_proceso', ['cliente_nombre' => 'Sin modelo']);
    $sinModelo->lineas()->update(['modelo' => null]);

    expect($varios->fresh()->modelosDeTrabajo())->toBe(['P-1', 'MD6040']);

    $this->actingAs($this->user)->get('/pedidos/produccion/etiquetas')->assertOk()
        ->assertSee('<p>P-1, MD6040</p>', false)
        ->assertSeeInOrder(['Sin modelo', '<p>—</p>'], false);
});

it('deja en blanco las casillas anteriores al inicio', function () {
    ($this->venta)();

    $respuesta = $this->actingAs($this->user)->get('/pedidos/produccion/etiquetas?inicio=10')->assertOk();

    expect(substr_count($respuesta->getContent(), 'class="planilla-vacia"'))->toBe(9);
});

it('toma como 1 un inicio fuera de rango o que no es número', function (string $inicio) {
    ($this->venta)();

    $respuesta = $this->actingAs($this->user)->get("/pedidos/produccion/etiquetas?inicio={$inicio}")->assertOk();

    expect(substr_count($respuesta->getContent(), 'class="planilla-vacia"'))->toBe(0);
})->with(['0', '25', 'abc', '-3']);

it('pasa a otra hoja cada 24 casillas, contando las vacías', function (int $ventas, int $inicio, int $hojas) {
    foreach (range(1, $ventas) as $i) {
        ($this->venta)();
    }

    $respuesta = $this->actingAs($this->user)->get("/pedidos/produccion/etiquetas?inicio={$inicio}")->assertOk();

    expect(substr_count($respuesta->getContent(), 'class="planilla-hoja"'))->toBe($hojas);
})->with([
    [24, 1, 1],
    [25, 1, 2],
    [15, 10, 1],
    [20, 10, 2],
]);

it('sin órdenes en proceso lo dice y no pinta la hoja', function () {
    $respuesta = $this->actingAs($this->user)->get('/pedidos/produccion/etiquetas')->assertOk()
        ->assertSee('No hay órdenes en proceso.')
        ->assertDontSee('data-imprimir', false);

    expect(substr_count($respuesta->getContent(), 'class="planilla-hoja"'))->toBe(0);
});

it('el listado de ventas y el dashboard enlazan las etiquetas', function () {
    $this->actingAs($this->user)->get('/pedidos')->assertOk()
        ->assertSee(route('pedidos.produccion.etiquetas'), false)
        ->assertSee('Imprimir etiquetas');

    $this->actingAs($this->user)->get('/dashboard')->assertOk()
        ->assertSee(route('pedidos.produccion.etiquetas'), false);
});
