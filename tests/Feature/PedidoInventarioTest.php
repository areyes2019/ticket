<?php

use App\Enums\MotivoMovimientoInventario;
use App\Models\Existencia;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->articulo = articuloFacturable($this->user);
});

it('descuenta existencias al crear, solo de las líneas con artículo', function () {
    marcarExistencia($this->articulo, 10);

    $this->actingAs($this->user)->post('/pedidos', datosPedido([lineaPedido($this->articulo, 3), lineaPedido(null, 5)]))
        ->assertSessionHasNoErrors();

    expect(Existencia::sole()->existencia)->toBe(7)
        ->and(MovimientoInventario::sole())
        ->motivo->toBe(MotivoMovimientoInventario::VentaPedido)
        ->cantidad->toBe(3)
        ->documentable_type->toBe('pedido');
});

it('rechaza un artículo sin existencia o fuera de existencias, sin crear nada', function (?int $existencia) {
    if ($existencia !== null) {
        marcarExistencia($this->articulo, $existencia);
    }

    $this->actingAs($this->user)->post('/pedidos', datosPedido([lineaPedido(null), lineaPedido($this->articulo, 1)]))
        ->assertSessionHasErrors('lineas.1.articulo_id');

    expect(Pedido::count())->toBe(0)
        ->and(MovimientoInventario::count())->toBe(0);
})->with(['sin fila' => null, 'en cero' => 0]);

it('rechaza un artículo que se quitó de existencias', function () {
    marcarExistencia($this->articulo, 5)->delete();

    $this->actingAs($this->user)->post('/pedidos', datosPedido([lineaPedido($this->articulo)]))
        ->assertSessionHasErrors('lineas.0.articulo_id');
});

it('vender más de lo disponible sí se permite y deja faltante', function () {
    marcarExistencia($this->articulo, 3);

    $this->actingAs($this->user)->post('/pedidos', datosPedido([lineaPedido($this->articulo, 5)]))->assertSessionHasNoErrors();

    expect(Existencia::sole())->existencia->toBe(0)->faltante_pendiente->toBe(2);
});

it('editar varias veces no descuenta varias veces, y uno que se llevó las últimas piezas se puede editar', function () {
    marcarExistencia($this->articulo, 4);
    $this->actingAs($this->user)->post('/pedidos', datosPedido([lineaPedido($this->articulo, 4)]));
    $pedido = Pedido::sole();

    foreach (['3', '4', '2'] as $cantidad) {
        $this->actingAs($this->user)->put("/pedidos/{$pedido->id}", datosPedido([lineaPedido($this->articulo, (int) $cantidad)]))
            ->assertSessionHasNoErrors();
    }

    expect(Existencia::sole())->existencia->toBe(2)->faltante_pendiente->toBe(0)
        ->and(MovimientoInventario::where('motivo', MotivoMovimientoInventario::CorreccionPedido)->count())->toBe(3);
});

it('borrar un pedido devuelve sus existencias', function () {
    marcarExistencia($this->articulo, 10);
    $this->actingAs($this->user)->post('/pedidos', datosPedido([lineaPedido($this->articulo, 4)]));

    $this->actingAs($this->user)->delete('/pedidos/'.Pedido::sole()->id)->assertRedirect('/pedidos');

    expect(Existencia::sole()->existencia)->toBe(10);
});
