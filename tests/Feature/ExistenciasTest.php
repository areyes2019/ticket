<?php

use App\Enums\EstadoCotizacion;
use App\Enums\EstadoOrdenCompra;
use App\Enums\MotivoMovimientoInventario;
use App\Models\Articulo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Existencia;
use App\Models\MovimientoInventario;
use App\Models\OrdenCompra;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create();
    // articuloFacturable(): costo $90, venta $135 (lista $100, 10% de descuento, 50% de utilidad).
    $this->articulo = articuloFacturable($this->user, ['modelo' => 'S-100', 'nombre' => 'Sello redondo']);
});

describe('acceso', function () {
    it('pide iniciar sesión', function (string $ruta) {
        $this->get($ruta)->assertRedirect(route('login'));
    })->with(['/existencias', '/existencias/agregar']);

    it('muestra Existencias en el grupo Inventario del menú', function () {
        $this->actingAs($this->user)->get('/dashboard')
            ->assertSee('Inventario')
            ->assertSee(route('existencias.index'));
    });

    it('responde 404 con un artículo ajeno', function (string $metodo, string $sufijo, array $datos) {
        $ajeno = articuloFacturable(User::factory()->create());
        marcarExistencia($ajeno, 5);

        $this->actingAs($this->user)->call($metodo, "/existencias/{$ajeno->id}{$sufijo}", $datos)->assertNotFound();

        expect(Existencia::sole()->existencia)->toBe(5)
            ->and(Existencia::sole()->trashed())->toBeFalse();
    })->with([
        'ficha' => ['GET', '', []],
        'ajuste' => ['POST', '/ajuste', ['cantidad' => 1, 'motivo' => 'conteo_fisico']],
        'parámetros' => ['PUT', '/parametros', ['minimo' => 1]],
        'quitar' => ['DELETE', '', []],
    ]);
});

describe('listado', function () {
    it('muestra solo los artículos marcados, nunca el resto del catálogo', function () {
        marcarExistencia($this->articulo, 4);
        $sinMarcar = articuloFacturable($this->user, ['modelo' => 'NO-MARCADO']);
        $quitado = articuloFacturable($this->user, ['modelo' => 'QUITADO']);
        marcarExistencia($quitado, 2)->delete();

        $this->actingAs($this->user)->get('/existencias')
            ->assertOk()
            ->assertSee('S-100')
            ->assertDontSee('NO-MARCADO')
            ->assertDontSee('QUITADO');

        $this->actingAs($this->user)->get('/existencias?q=NO-MARCADO')->assertDontSee($sinMarcar->modelo.'</a>', false);
    });

    it('calcula los totales sobre el conjunto filtrado completo, no sobre la página', function () {
        foreach (range(1, 16) as $i) {
            marcarExistencia(articuloFacturable($this->user, ['modelo' => sprintf('M-%02d', $i)]), 2);
        }

        // 16 artículos × 2 piezas: 32 unidades; invertido 32 × $90; beneficio 32 × $46.21
        // (venta $136.21: $158.00 con IVA, porque $157.00 es inalcanzable).
        foreach (['/existencias', '/existencias?page=2'] as $ruta) {
            $this->actingAs($this->user)->get($ruta)
                ->assertViewHas('totales', ['unidades' => 32, 'invertido' => 2880.0, 'beneficio' => 1478.72, 'total' => 4358.72]);
        }

        $this->actingAs($this->user)->get('/existencias?q=M-01')
            ->assertViewHas('totales', fn (array $totales) => $totales['unidades'] === 2);
    });

    it('filtra por proveedor y por catálogo', function () {
        marcarExistencia($this->articulo, 3);
        $otro = articuloFacturable($this->user, ['modelo' => 'OTRO']);
        marcarExistencia($otro, 7);

        $this->actingAs($this->user)->get("/existencias?proveedor={$otro->proveedor_id}")
            ->assertViewHas('totales', fn (array $totales) => $totales['unidades'] === 7);
        $this->actingAs($this->user)->get("/existencias?catalogo={$this->articulo->catalogo_id}")
            ->assertViewHas('totales', fn (array $totales) => $totales['unidades'] === 3);
    });

    it('ordena por dinero invertido todo el conjunto, no solo la página', function () {
        foreach (range(1, 15) as $i) {
            marcarExistencia(articuloFacturable($this->user, ['modelo' => sprintf('A-%02d', $i)]), 1);
        }
        marcarExistencia($this->articulo, 50);

        $this->actingAs($this->user)->get('/existencias?orden=invertido&direccion=desc')
            ->assertViewHas('existencias', fn ($pagina) => $pagina->first()->articulo_id === $this->articulo->id);
    });

    it('filtra solo los por pedir y cuenta cuántos hay', function () {
        marcarExistencia($this->articulo, 1, minimo: 5);
        marcarExistencia(articuloFacturable($this->user, ['modelo' => 'EN-MINIMO']), 5, minimo: 5);

        $this->actingAs($this->user)->get('/existencias?por_pedir=1')
            ->assertSee('S-100')
            ->assertDontSee('EN-MINIMO')
            ->assertViewHas('porPedir', 1);
    });

    it('responde el fragmento de la búsqueda dinámica con totales', function () {
        marcarExistencia($this->articulo, 3);

        $this->actingAs($this->user)->get('/existencias/buscar?q=S-1', cabecerasAjax())
            ->assertOk()
            ->assertSee('id="existencias-totales"', false)
            ->assertSee('id="existencias-filas"', false)
            ->assertDontSee('<html', false);
    });
});

describe('alta, ajuste y quitar', function () {
    it('el buscador ofrece solo artículos que no están en existencias', function () {
        articuloFacturable($this->user, ['modelo' => 'S-200']);
        marcarExistencia($this->articulo, 1);

        $this->actingAs($this->user)->get('/existencias/agregar?q=S-')
            ->assertSee('S-200')
            ->assertDontSee('S-100');
    });

    it('la ficha de un artículo sin fila abre el alta al cargar', function () {
        $this->actingAs($this->user)->get("/existencias/{$this->articulo->id}")
            ->assertOk()
            ->assertSee('Este artículo no está en existencias')
            ->assertSee('id="dialogo-ajuste"', false)
            ->assertSee('data-abrir-al-cargar', false);
    });

    it('el alta manual crea la fila y deja un movimiento', function () {
        $this->actingAs($this->user)->post("/existencias/{$this->articulo->id}/ajuste", ['cantidad' => 12, 'motivo' => 'entrada_inicial', 'nota' => 'Apertura'])
            ->assertRedirect(route('existencias.show', $this->articulo))
            ->assertSessionHas('exito', 'S-100 pasó a existencias con 12 piezas.');

        expect(Existencia::sole()->existencia)->toBe(12)
            ->and(MovimientoInventario::sole())->motivo->toBe(MotivoMovimientoInventario::EntradaInicial)->nota->toBe('Apertura');
    });

    it('rechaza un motivo automático y una cantidad negativa', function (array $datos, string $campo) {
        $this->actingAs($this->user)->post("/existencias/{$this->articulo->id}/ajuste", $datos)
            ->assertSessionHasErrorsIn('ajuste', $campo);

        expect(Existencia::count())->toBe(0);
    })->with([
        'motivo automático' => [['cantidad' => 5, 'motivo' => 'recepcion_orden'], 'motivo'],
        'sin motivo' => [['cantidad' => 5], 'motivo'],
        'negativa' => [['cantidad' => -1, 'motivo' => 'merma'], 'cantidad'],
    ]);

    it('guarda mínimo y máximo sin generar movimiento', function () {
        marcarExistencia($this->articulo, 3);

        $this->actingAs($this->user)->put("/existencias/{$this->articulo->id}/parametros", ['minimo' => 5, 'maximo' => 20])->assertSessionHas('exito');

        expect(Existencia::sole())->minimo->toBe(5)->maximo->toBe(20)
            ->and(MovimientoInventario::count())->toBe(0);
    });

    it('rechaza un máximo menor que el mínimo, y parámetros sin fila son 404', function () {
        marcarExistencia($this->articulo, 3);

        $this->actingAs($this->user)->put("/existencias/{$this->articulo->id}/parametros", ['minimo' => 5, 'maximo' => 2])
            ->assertSessionHasErrorsIn('parametros', 'maximo');

        $sinFila = articuloFacturable($this->user);
        $this->actingAs($this->user)->put("/existencias/{$sinFila->id}/parametros", ['minimo' => 5])->assertNotFound();
    });

    it('quitar oculta del listado, conserva el historial y volver a marcar restaura la fila', function () {
        $this->actingAs($this->user)->post("/existencias/{$this->articulo->id}/ajuste", ['cantidad' => 4, 'motivo' => 'conteo_fisico']);
        $fila = Existencia::sole();
        $fila->forceFill(['minimo' => 2])->save();

        $this->actingAs($this->user)->delete("/existencias/{$this->articulo->id}")->assertRedirect(route('existencias.index'));

        $this->actingAs($this->user)->get('/existencias')->assertViewHas('existencias', fn ($pagina) => $pagina->isEmpty());
        $this->actingAs($this->user)->get("/existencias/{$this->articulo->id}")
            ->assertSee('Conteo físico')
            ->assertSee('se recuperan sus mínimos anteriores');

        $this->actingAs($this->user)->post("/existencias/{$this->articulo->id}/ajuste", ['cantidad' => 1, 'motivo' => 'conteo_fisico']);

        expect(Existencia::sole())->id->toBe($fila->id)->minimo->toBe(2)->existencia->toBe(1)
            ->and(MovimientoInventario::count())->toBe(2);
    });

    it('la ficha enlaza el historial con su documento origen', function () {
        $orden = OrdenCompra::factory()->for($this->articulo->proveedor)->enEstado(EstadoOrdenCompra::Pagada)->create();
        agregarLinea($orden, $this->articulo, 3);
        $this->actingAs($this->user)->post("/ordenes-compra/{$orden->id}/recibir");

        $this->actingAs($this->user)->get("/existencias/{$this->articulo->id}")
            ->assertSee(route('ordenes-compra.show', $orden))
            ->assertSee($orden->folio_formateado);
    });
});

describe('generar órdenes de compra', function () {
    it('crea un borrador con las cantidades sugeridas y lleva a su detalle', function () {
        marcarExistencia($this->articulo, 3, faltante: 0, minimo: 5, maximo: 20);
        marcarExistencia(articuloFacturable($this->user, ['modelo' => 'SOBRADO']), 30, minimo: 5);

        $respuesta = $this->actingAs($this->user)->post('/existencias/generar-ordenes-compra');

        $orden = OrdenCompra::sole();
        $respuesta->assertRedirect(route('ordenes-compra.show', $orden));

        $linea = $orden->lineas()->sole();
        expect($orden)->estado->toBe(EstadoOrdenCompra::Borrador)->folio->toBe(1)->proveedor_id->toBe($this->articulo->proveedor_id)
            ->and($linea->only(['articulo_id', 'cantidad', 'modelo']))->toBe(['articulo_id' => $this->articulo->id, 'cantidad' => 17, 'modelo' => 'S-100'])
            ->and($linea->precio_unitario)->toBe('90.00')
            // 17 × $90 = $1,530 + 16% de IVA.
            ->and($orden->total)->toBe('1774.80');
    });

    it('con varios proveedores crea una orden por cada uno y lleva a la bandeja', function () {
        marcarExistencia($this->articulo, 0, faltante: 2);
        marcarExistencia(articuloFacturable($this->user), 0, faltante: 1);

        $this->actingAs($this->user)->post('/existencias/generar-ordenes-compra')
            ->assertRedirect(route('ordenes-compra.index'));

        expect(OrdenCompra::count())->toBe(2)
            ->and(OrdenCompra::pluck('folio')->sort()->values()->all())->toBe([1, 2]);
    });

    it('omite y reporta los artículos de un catálogo o proveedor eliminado', function () {
        marcarExistencia($this->articulo, 0, faltante: 2);
        $huerfano = articuloFacturable($this->user, ['modelo' => 'HUERFANO']);
        marcarExistencia($huerfano, 0, faltante: 1);
        DB::table('catalogos')->where('id', $huerfano->catalogo_id)->update(['deleted_at' => now()]);

        $this->actingAs($this->user)->post('/existencias/generar-ordenes-compra')
            ->assertSessionHas('exito', fn (string $mensaje) => str_contains($mensaje, 'HUERFANO (catálogo eliminado)'));

        expect(OrdenCompra::sole()->lineas()->sole()->articulo_id)->toBe($this->articulo->id);
    });

    it('sin artículos por pedir no crea nada', function () {
        marcarExistencia($this->articulo, 10, minimo: 5);

        $this->actingAs($this->user)->post('/existencias/generar-ordenes-compra')
            ->assertRedirect(route('existencias.index'))
            ->assertSessionHas('error');

        expect(OrdenCompra::count())->toBe(0);
    });
});

describe('pantallas existentes', function () {
    it('el listado de artículos muestra si cada uno está en existencias, sin una consulta por fila', function () {
        marcarExistencia($this->articulo, 1);
        foreach (range(1, 5) as $i) {
            articuloFacturable($this->user);
        }

        DB::enableQueryLog();
        $respuesta = $this->actingAs($this->user)->get('/articulos');
        $consultas = collect(DB::getQueryLog())->filter(fn (array $consulta) => str_contains($consulta['query'], 'existencias'));

        $respuesta->assertSee('title="Pasar a existencias">No</a>', false)->assertSee(route('existencias.show', $this->articulo));
        expect($consultas)->toHaveCount(1);
    });

    it('el formulario de una factura que viene de una cotización avisa quién descuenta', function () {
        $cotizacion = Cotizacion::factory()
            ->for(Cliente::factory()->for($this->user))
            ->enEstado(EstadoCotizacion::Pagada)
            ->create(['user_id' => $this->user->id]);
        agregarLinea($cotizacion, $this->articulo, 1);

        $this->actingAs($this->user)->get("/facturas/crear?cotizacion={$cotizacion->id}")
            ->assertSee('El inventario se descontará al marcar la cotización como entregada');
    });
});

it('no deja ver la ficha de un artículo borrado', function () {
    $this->articulo->delete();

    $this->actingAs($this->user)->get("/existencias/{$this->articulo->id}")->assertNotFound();
});

it('Articulo::existencia no incluye la fila quitada', function () {
    marcarExistencia($this->articulo, 1)->delete();

    expect(Articulo::find($this->articulo->id)->existencia)->toBeNull();
});
