<?php

use App\Enums\ColorTinta;
use App\Enums\EstadoOrdenTrabajo;
use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\OrdenTrabajo;
use App\Models\OrdenTrabajoLinea;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->cuenta = Cuenta::factory()->for($this->user)->create();
    // $1,160.00: 10 × $100 + IVA, una línea libre "Sello de prueba" modelo P-1.
    $this->pedido = Pedido::factory()->for($this->user)->conLinea(10)->create();

    $this->pagar = fn (Pedido $pedido, string $monto = '100.00') => $this->actingAs($this->user)->post("/pedidos/{$pedido->id}/pagos", [
        'cuenta_id' => $this->cuenta->id,
        'fecha_pago' => today('America/Mexico_City')->toDateString(),
        'monto' => $monto,
    ]);

    // Un color por cada línea de la venta, con el id de la línea como clave.
    $this->colores = fn (Pedido $pedido, string ...$colores) => $pedido->lineas()->get()->values()
        ->mapWithKeys(fn ($linea, $i) => [$linea->id => ['color' => $colores[$i] ?? $colores[0], 'otro' => '']])
        ->all();

    $this->crearOrden = function (Pedido $pedido, array $datos = []) {
        return $this->actingAs($this->user)->post("/pedidos/{$pedido->id}/orden-trabajo", [
            'colores' => ($this->colores)($pedido, 'azul'),
            ...$datos,
        ]);
    };
});

/**
 * Línea libre del formulario de la venta con la descripción indicada.
 *
 * @return array<string, mixed>
 */
function lineaVentaLibre(string $descripcion, int $cantidad = 1, string $precio = '100.00'): array
{
    return [...lineaPedido(null, $cantidad, $precio), 'descripcion' => $descripcion, 'modelo' => 'P-1'];
}

it('pide sesión en todas las rutas de la orden de trabajo', function (string $metodo, string $url) {
    $this->call($metodo, str_replace('{id}', (string) $this->pedido->id, $url))->assertRedirect('/login');
})->with([
    ['GET', '/pedidos/produccion'],
    ['GET', '/pedidos/{id}/orden-trabajo/crear'],
    ['POST', '/pedidos/{id}/orden-trabajo'],
    ['GET', '/pedidos/{id}/orden-trabajo'],
    ['GET', '/pedidos/{id}/orden-trabajo/vista-previa'],
    ['GET', '/pedidos/{id}/orden-trabajo/editar'],
    ['PUT', '/pedidos/{id}/orden-trabajo'],
    ['POST', '/pedidos/{id}/orden-trabajo/avanzar'],
    ['GET', '/pedidos/{id}/orden-trabajo/imprimir'],
    ['GET', '/pedidos/{id}/orden-trabajo/imagen'],
]);

it('la orden de una venta ajena responde 404', function (string $metodo, string $url) {
    $ajeno = User::factory()->create();
    $pedido = Pedido::factory()->for($ajeno)->conLinea()->create();
    $orden = new OrdenTrabajo;
    $orden->forceFill(['user_id' => $ajeno->id, 'pedido_id' => $pedido->id])->save();

    $this->actingAs($this->user)->call($metodo, str_replace('{id}', (string) $pedido->id, $url))->assertNotFound();
})->with([
    ['GET', '/pedidos/{id}/orden-trabajo/crear'],
    ['POST', '/pedidos/{id}/orden-trabajo'],
    ['GET', '/pedidos/{id}/orden-trabajo'],
    ['GET', '/pedidos/{id}/orden-trabajo/vista-previa'],
    ['GET', '/pedidos/{id}/orden-trabajo/editar'],
    ['PUT', '/pedidos/{id}/orden-trabajo'],
    ['POST', '/pedidos/{id}/orden-trabajo/avanzar'],
    ['GET', '/pedidos/{id}/orden-trabajo/imprimir'],
    ['GET', '/pedidos/{id}/orden-trabajo/imagen'],
]);

it('sin orden, ver, editar, avanzar e imprimir responden 404', function (string $metodo, string $url) {
    ($this->pagar)($this->pedido);

    $this->actingAs($this->user)->call($metodo, str_replace('{id}', (string) $this->pedido->id, $url))->assertNotFound();
})->with([
    ['GET', '/pedidos/{id}/orden-trabajo'],
    ['GET', '/pedidos/{id}/orden-trabajo/vista-previa'],
    ['GET', '/pedidos/{id}/orden-trabajo/editar'],
    ['PUT', '/pedidos/{id}/orden-trabajo'],
    ['POST', '/pedidos/{id}/orden-trabajo/avanzar'],
    ['GET', '/pedidos/{id}/orden-trabajo/imprimir'],
]);

it('sin pagos no se crea la orden', function () {
    $this->actingAs($this->user)->get("/pedidos/{$this->pedido->id}/orden-trabajo/crear")
        ->assertRedirect("/pedidos/{$this->pedido->id}")
        ->assertSessionHas('error', 'Registra un pago antes de crear la orden de trabajo.');
    ($this->crearOrden)($this->pedido)->assertForbidden();

    $this->actingAs($this->user)->get("/pedidos/{$this->pedido->id}")
        ->assertSee('title="Registra un pago antes de crear la orden de trabajo."', false);

    expect(OrdenTrabajo::count())->toBe(0);
});

it('con un anticipo de cualquier monto se crea la orden en dibujo', function () {
    ($this->pagar)($this->pedido, '0.01');

    $this->actingAs($this->user)->get("/pedidos/{$this->pedido->id}/orden-trabajo/crear")
        ->assertOk()
        ->assertSee($this->pedido->cliente_nombre)
        ->assertSee('Sello de prueba');

    ($this->crearOrden)($this->pedido)
        ->assertSessionHasNoErrors()
        ->assertRedirect("/pedidos/{$this->pedido->id}/orden-trabajo");

    $orden = OrdenTrabajo::sole();

    expect($orden->estado)->toBe(EstadoOrdenTrabajo::EnDibujo)
        ->and($orden->pedido_id)->toBe($this->pedido->id)
        ->and($orden->user_id)->toBe($this->user->id)
        ->and($orden->lineas()->sole()->color_tinta)->toBe(ColorTinta::Azul);

    $this->actingAs($this->user)->get("/pedidos/{$this->pedido->id}/orden-trabajo")
        ->assertOk()
        ->assertSee('PED-0001')
        ->assertSee($this->pedido->cliente_nombre)
        ->assertSee($this->pedido->telefono_legible)
        ->assertSee('P-1')
        ->assertSee('Sello de prueba')
        ->assertSee('Azul')
        ->assertSee('Pasar a En proceso');
});

it('la venta de una cotización aceptada también admite orden', function () {
    $venta = Pedido::factory()->for($this->user)->conLinea()->create();
    $venta->forceFill(['cotizacion_id' => Cotizacion::factory()->for($this->user)->create()->id])->save();
    ($this->pagar)($venta, '10.00');

    ($this->crearOrden)($venta)->assertSessionHasNoErrors();

    expect($venta->fresh()->ordenTrabajo)->not->toBeNull();
});

it('exige un color válido por cada línea y el texto de "Otro"', function () {
    ($this->pagar)($this->pedido);
    $linea = $this->pedido->lineas()->first();

    ($this->crearOrden)($this->pedido, ['colores' => [$linea->id => ['color' => '']]])
        ->assertSessionHasErrors(["colores.{$linea->id}.color" => 'Elige el color de tinta de Sello de prueba.']);
    ($this->crearOrden)($this->pedido, ['colores' => [$linea->id => ['color' => 'dorado']]])
        ->assertSessionHasErrors("colores.{$linea->id}.color");
    ($this->crearOrden)($this->pedido, ['colores' => [$linea->id => ['color' => 'otro', 'otro' => '']]])
        ->assertSessionHasErrors(["colores.{$linea->id}.otro" => 'Escribe el color de tinta de Sello de prueba.']);

    $ajena = Pedido::factory()->for($this->user)->conLinea()->create()->lineas()->first();
    ($this->crearOrden)($this->pedido, ['colores' => [$linea->id => ['color' => 'azul'], $ajena->id => ['color' => 'rojo']]])
        ->assertSessionHasErrors('colores');

    expect(OrdenTrabajo::count())->toBe(0);
});

it('"Otro" guarda el texto y los demás colores lo descartan', function () {
    ($this->pagar)($this->pedido);
    $linea = $this->pedido->lineas()->first();

    ($this->crearOrden)($this->pedido, ['colores' => [$linea->id => ['color' => 'otro', 'otro' => ' Dorado ']]])->assertSessionHasNoErrors();
    expect(OrdenTrabajo::sole()->lineas()->sole()->colorTexto())->toBe('Dorado');

    $this->actingAs($this->user)->put("/pedidos/{$this->pedido->id}/orden-trabajo", ['colores' => [$linea->id => ['color' => 'negro', 'otro' => 'Dorado']]])
        ->assertSessionHasNoErrors();
    expect(OrdenTrabajo::sole()->lineas()->sole())
        ->color_tinta->toBe(ColorTinta::Negro)
        ->color_tinta_otro->toBeNull();
});

it('una venta tiene una sola orden', function () {
    ($this->pagar)($this->pedido);

    ($this->crearOrden)($this->pedido)->assertSessionHasNoErrors();
    ($this->crearOrden)($this->pedido)->assertForbidden();

    $this->actingAs($this->user)->get("/pedidos/{$this->pedido->id}/orden-trabajo/crear")
        ->assertSessionHas('error', 'Ya tiene orden de trabajo.');

    expect(OrdenTrabajo::count())->toBe(1);
});

it('guarda la imagen del diseño reducida, la reemplaza y la quita', function () {
    Storage::fake('local');
    ($this->pagar)($this->pedido);

    ($this->crearOrden)($this->pedido, ['imagen' => UploadedFile::fake()->image('diseno.jpg', 2400, 1200)])->assertSessionHasNoErrors();

    $orden = OrdenTrabajo::sole();
    $primera = $orden->imagen_ruta;

    expect($primera)->toStartWith('ordenes-trabajo/'.$orden->id.'-')->toEndWith('.webp');
    Storage::disk('local')->assertExists($primera);
    expect(getimagesizefromstring(Storage::disk('local')->get($primera))[0])->toBe(1200);

    $this->actingAs($this->user)->get("/pedidos/{$this->pedido->id}/orden-trabajo/imagen")
        ->assertOk()->assertHeader('Content-Type', 'image/webp');

    $this->actingAs($this->user)->put("/pedidos/{$this->pedido->id}/orden-trabajo", [
        'colores' => ($this->colores)($this->pedido, 'rojo'),
        'imagen' => UploadedFile::fake()->image('otra.png', 300, 300),
    ])->assertSessionHasNoErrors();

    $segunda = $orden->fresh()->imagen_ruta;
    Storage::disk('local')->assertMissing($primera);
    Storage::disk('local')->assertExists($segunda);

    $this->actingAs($this->user)->put("/pedidos/{$this->pedido->id}/orden-trabajo", [
        'colores' => ($this->colores)($this->pedido, 'rojo'),
        'quitar_imagen' => '1',
    ])->assertSessionHasNoErrors();

    expect($orden->fresh()->imagen_ruta)->toBeNull();
    Storage::disk('local')->assertMissing($segunda);
    $this->actingAs($this->user)->get("/pedidos/{$this->pedido->id}/orden-trabajo/imagen")->assertNotFound();
});

it('rechaza un archivo que no es imagen aunque diga .jpg', function () {
    Storage::fake('local');
    ($this->pagar)($this->pedido);

    ($this->crearOrden)($this->pedido, ['imagen' => UploadedFile::fake()->createWithContent('diseno.jpg', 'no soy una imagen')])
        ->assertSessionHasErrors('imagen');

    expect(OrdenTrabajo::count())->toBe(0);
});

it('avanza en dibujo → en proceso → terminado, sin regreso', function () {
    ($this->pagar)($this->pedido);
    ($this->crearOrden)($this->pedido);
    $avanzar = fn () => $this->actingAs($this->user)->post("/pedidos/{$this->pedido->id}/orden-trabajo/avanzar");

    $avanzar()->assertSessionHas('exito', 'PED-0001 pasó a En proceso.');
    expect(OrdenTrabajo::sole()->estado)->toBe(EstadoOrdenTrabajo::EnProceso);

    $avanzar()->assertSessionHas('exito', 'PED-0001 pasó a Terminado.');
    expect(OrdenTrabajo::sole()->estado)->toBe(EstadoOrdenTrabajo::Terminado);

    $avanzar()->assertSessionHas('error', 'La orden ya está terminada.');
    expect(OrdenTrabajo::sole()->estado)->toBe(EstadoOrdenTrabajo::Terminado);

    $this->actingAs($this->user)->get("/pedidos/{$this->pedido->id}/orden-trabajo")
        ->assertDontSee('Pasar a');
});

it('la vista previa del dashboard muestra la orden con sus acciones', function () {
    ($this->pagar)($this->pedido);
    ($this->crearOrden)($this->pedido);
    $orden = OrdenTrabajo::sole();

    $this->actingAs($this->user)->get("/pedidos/{$this->pedido->id}/orden-trabajo/vista-previa")
        ->assertOk()
        ->assertDontSee('<html', false)
        ->assertSee('data-vista-previa-de="'.$orden->id.'" data-documento="ot"', false)
        ->assertSee('Orden de trabajo · PED-0001')
        ->assertSee('Sello de prueba')
        ->assertSee('Azul')
        ->assertSee('Pasar a En proceso')
        ->assertSee('name="origen" value="dashboard"', false)
        ->assertSee(route('pedidos.orden-trabajo.edit', $this->pedido), false)
        ->assertSee(route('pedidos.orden-trabajo.imprimir', $this->pedido), false)
        ->assertSee(route('pedidos.orden-trabajo.show', $this->pedido), false);
});

it('avanzar desde el dashboard regresa a él con la orden abierta', function () {
    ($this->pagar)($this->pedido);
    ($this->crearOrden)($this->pedido);
    $orden = OrdenTrabajo::sole();

    $this->actingAs($this->user)->post("/pedidos/{$this->pedido->id}/orden-trabajo/avanzar", ['origen' => 'dashboard'])
        ->assertRedirect(route('dashboard', ['ot' => $orden->id]))
        ->assertSessionHas('exito', 'PED-0001 pasó a En proceso.');

    $this->actingAs($this->user)->post("/pedidos/{$this->pedido->id}/orden-trabajo/avanzar")
        ->assertRedirect(route('pedidos.orden-trabajo.show', $this->pedido));
});

it('una venta entregada deja la orden solo para consulta, y deshacer la entrega la libera', function () {
    ($this->pagar)($this->pedido);
    ($this->crearOrden)($this->pedido);
    $this->pedido->refresh()->marcarEntregado();
    $this->pedido->save();

    $this->actingAs($this->user)->get("/pedidos/{$this->pedido->id}/orden-trabajo")->assertOk()->assertDontSee('Editar');
    $this->actingAs($this->user)->get("/pedidos/{$this->pedido->id}/orden-trabajo/vista-previa")->assertOk()
        ->assertDontSee('Editar')
        ->assertDontSee('Pasar a');
    $this->actingAs($this->user)->get("/pedidos/{$this->pedido->id}/orden-trabajo/imprimir")->assertOk();
    $this->actingAs($this->user)->get("/pedidos/{$this->pedido->id}/orden-trabajo/editar")->assertForbidden();
    $this->actingAs($this->user)->put("/pedidos/{$this->pedido->id}/orden-trabajo", ['colores' => ($this->colores)($this->pedido, 'rojo')])->assertForbidden();
    $this->actingAs($this->user)->post("/pedidos/{$this->pedido->id}/orden-trabajo/avanzar")->assertSessionHas('error', 'La venta ya se entregó.');

    $this->pedido->deshacerEntrega();
    $this->pedido->save();

    $this->actingAs($this->user)->get("/pedidos/{$this->pedido->id}/orden-trabajo/editar")
        ->assertOk()
        ->assertSee('value="azul" selected', false);
});

it('editar las líneas de la venta conserva los colores de las que siguen', function () {
    $venta = Pedido::factory()->for($this->user)->create();
    $this->actingAs($this->user)->put("/pedidos/{$venta->id}", datosPedido([lineaVentaLibre('Sello A'), lineaVentaLibre('Sello A'), lineaVentaLibre('Fechador')]))
        ->assertSessionHasNoErrors();
    ($this->pagar)($venta, '10.00');
    $this->actingAs($this->user)->post("/pedidos/{$venta->id}/orden-trabajo", ['colores' => ($this->colores)($venta, 'rojo', 'azul', 'verde')])
        ->assertSessionHasNoErrors();

    // Quita el fechador, conserva los dos "Sello A" y agrega una línea nueva.
    $this->actingAs($this->user)->put("/pedidos/{$venta->id}", datosPedido([lineaVentaLibre('sello a '), lineaVentaLibre('Sello A'), lineaVentaLibre('Foliador')]))
        ->assertSessionHasNoErrors();

    $orden = $venta->fresh()->ordenTrabajo->load('pedido.lineas', 'lineas');
    $colores = $orden->pedido->lineas->map(fn ($linea) => $orden->colorDe($linea)?->color_tinta)->all();

    expect($colores)->toBe([ColorTinta::Rojo, ColorTinta::Azul, null])
        ->and($orden->lineasSinColor()->pluck('descripcion')->all())->toBe(['Foliador'])
        ->and($orden->motivoNoAvanza())->toBe('Falta el color de tinta de: Foliador.');

    $this->actingAs($this->user)->post("/pedidos/{$venta->id}/orden-trabajo/avanzar")
        ->assertSessionHas('error', 'Falta el color de tinta de: Foliador.');
    $this->actingAs($this->user)->get("/pedidos/{$venta->id}/orden-trabajo")
        ->assertSee('Falta el color de tinta de: Foliador.');
});

it('borrar los pagos conserva la orden, y borrar la venta se lleva la orden y su imagen', function () {
    Storage::fake('local');
    ($this->pagar)($this->pedido);
    ($this->crearOrden)($this->pedido, ['imagen' => UploadedFile::fake()->image('diseno.jpg')]);
    $ruta = OrdenTrabajo::sole()->imagen_ruta;

    $pago = $this->pedido->pagos()->sole();
    $this->actingAs($this->user)->delete("/pedidos/{$this->pedido->id}/pagos/{$pago->id}")->assertSessionHas('exito');
    expect(OrdenTrabajo::count())->toBe(1);

    $this->actingAs($this->user)->delete("/pedidos/{$this->pedido->id}")->assertRedirect('/pedidos');

    expect(OrdenTrabajo::count())->toBe(0)
        ->and(OrdenTrabajoLinea::count())->toBe(0);
    Storage::disk('local')->assertMissing($ruta);
});

it('el listado marca "Sin orden" solo en las ventas cobradas sin orden', function () {
    $sinPago = Pedido::factory()->for($this->user)->conLinea()->create();
    $conPago = Pedido::factory()->for($this->user)->conLinea()->create();
    $conOrden = Pedido::factory()->for($this->user)->conLinea()->create();
    $entregada = Pedido::factory()->for($this->user)->conLinea()->create();
    ($this->pagar)($conPago, '10.00');
    ($this->pagar)($conOrden, '10.00');
    ($this->pagar)($entregada, '116.00');
    ($this->crearOrden)($conOrden);
    $entregada->refresh()->marcarEntregado();
    $entregada->save();

    expect($sinPago->fresh()->necesitaOrdenTrabajo())->toBeFalse()
        ->and($conPago->fresh()->necesitaOrdenTrabajo())->toBeTrue()
        ->and($conOrden->fresh()->necesitaOrdenTrabajo())->toBeFalse()
        ->and($entregada->fresh()->necesitaOrdenTrabajo())->toBeFalse();

    $respuesta = $this->actingAs($this->user)->get('/pedidos')->assertOk();
    expect(substr_count($respuesta->getContent(), '>Sin orden</span>'))->toBe(1);
});

it('la hoja de producción imprime solo las órdenes en proceso del usuario, con su miniatura', function () {
    Storage::fake('local');
    $enProceso = Pedido::factory()->for($this->user)->conLinea()->create();
    $enDibujo = Pedido::factory()->for($this->user)->conLinea()->create(['cliente_nombre' => 'Cliente en dibujo']);
    foreach ([$enProceso, $enDibujo] as $venta) {
        ($this->pagar)($venta, '10.00');
    }
    ($this->crearOrden)($enProceso, ['imagen' => UploadedFile::fake()->image('diseno.jpg')]);
    ($this->crearOrden)($enDibujo);
    $this->actingAs($this->user)->post("/pedidos/{$enProceso->id}/orden-trabajo/avanzar");

    $ajeno = User::factory()->create();
    $ventaAjena = Pedido::factory()->for($ajeno)->conLinea()->create(['cliente_nombre' => 'Cliente ajeno']);
    $ordenAjena = new OrdenTrabajo;
    $ordenAjena->forceFill(['user_id' => $ajeno->id, 'pedido_id' => $ventaAjena->id, 'estado' => 'en_proceso'])->save();

    $this->actingAs($this->user)->get('/pedidos/produccion')
        ->assertOk()
        ->assertSee($enProceso->folio_formateado)
        ->assertSee($enProceso->cliente_nombre)
        ->assertSee('Azul')
        ->assertSee(route('pedidos.orden-trabajo.imagen', [$enProceso, 'v' => $enProceso->ordenTrabajo->imagen_version]), false)
        ->assertSee('imprimir-al-cargar.js')
        ->assertDontSee('Cliente en dibujo')
        ->assertDontSee('Cliente ajeno');
});

it('la hoja de producción sin órdenes lo dice y no abre la impresión', function () {
    $this->actingAs($this->user)->get('/pedidos/produccion')
        ->assertOk()
        ->assertSee('No hay órdenes en proceso.')
        ->assertDontSee('imprimir-al-cargar.js');
});
