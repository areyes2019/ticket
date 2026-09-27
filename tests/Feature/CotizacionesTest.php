<?php

use App\Enums\EstadoCotizacion;
use App\Enums\ObjetoImpuesto;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionLinea;
use App\Models\Proveedor;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * @param  array<string, mixed>  $cambios
 * @return array<string, mixed>
 */
function lineaLibre(array $cambios = []): array
{
    return [
        'articulo_id' => '',
        'cantidad' => '2',
        'descripcion' => 'Sello personalizado',
        'modelo' => '',
        'precio_unitario' => '100.00',
        'descuento_tipo' => '',
        'descuento_valor' => '',
        'tasa_iva' => '16',
        ...$cambios,
    ];
}

/**
 * @param  list<array<string, mixed>>  $lineas
 * @param  array<string, mixed>  $cambios
 * @return array<string, mixed>
 */
function datosCotizacion(Cliente $cliente, array $lineas = [], array $cambios = []): array
{
    return [
        'cliente_id' => $cliente->id,
        'lineas' => $lineas ?: [lineaLibre()],
        'descuento_global_tipo' => '',
        'descuento_global_valor' => '',
        ...$cambios,
    ];
}

function articuloDe(User $user, array $atributos = []): Articulo
{
    $catalogo = Catalogo::factory()->conDescuento(10)->conUtilidad(50)
        ->for(Proveedor::factory()->for($user))
        ->create(['user_id' => $user->id]);

    return Articulo::factory()->for($catalogo)->create(['user_id' => $user->id, 'precio_proveedor' => '100.00', ...$atributos]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->cliente = Cliente::factory()->for($this->user)->create();
});

describe('acceso', function () {
    it('pide iniciar sesión', function (string $ruta) {
        $this->get($ruta)->assertRedirect(route('login'));
    })->with(['/cotizaciones', '/cotizaciones/crear']);

    it('responde 401 a las peticiones AJAX sin sesión', function (string $ruta) {
        $this->get($ruta, cabecerasAjax())->assertUnauthorized();
    })->with(['/cotizaciones/buscar', '/articulos/sugerencias?q=sello']);

    it('saca a un usuario suspendido', function () {
        $this->actingAs(User::factory()->suspendido()->create())
            ->get('/cotizaciones')
            ->assertRedirect(route('login'));
    });

    it('muestra el enlace de cotizaciones en el menú', function () {
        $this->actingAs($this->user)->get('/dashboard')
            ->assertSee(route('cotizaciones.index'))
            ->assertSee('bi-file-earmark-text', false);
    });

    it('responde 404 en todas las acciones sobre una cotización ajena', function (string $metodo, string $ruta) {
        $ajena = Cotizacion::factory()->conLinea()->create();

        $this->actingAs($this->user)
            ->call($metodo, str_replace('{id}', (string) $ajena->id, $ruta), ['tipo' => 'anticipo', 'destinatarios_texto' => 'a@b.mx'])
            ->assertNotFound();
    })->with([
        ['GET', '/cotizaciones/{id}'],
        ['GET', '/cotizaciones/{id}/editar'],
        ['PUT', '/cotizaciones/{id}'],
        ['DELETE', '/cotizaciones/{id}'],
        ['GET', '/cotizaciones/{id}/pdf'],
        ['POST', '/cotizaciones/{id}/enviar'],
        ['POST', '/cotizaciones/{id}/marcar-enviada'],
        ['POST', '/cotizaciones/{id}/pagos'],
        ['POST', '/cotizaciones/{id}/entregar'],
        ['POST', '/cotizaciones/{id}/duplicar'],
    ]);
});

describe('alta', function () {
    it('crea una cotización en borrador con totales calculados en el servidor', function () {
        $respuesta = $this->actingAs($this->user)->post('/cotizaciones', datosCotizacion($this->cliente, [], [
            'total' => '1.00',
            'subtotal' => '1.00',
            'estado' => 'pagada',
            'folio' => 99,
            'user_id' => User::factory()->create()->id,
        ]));

        $cotizacion = Cotizacion::sole();
        $respuesta->assertRedirect(route('cotizaciones.show', $cotizacion));

        expect($cotizacion->user_id)->toBe($this->user->id)
            ->and($cotizacion->folio)->toBe(1)
            ->and($cotizacion->estado)->toBe(EstadoCotizacion::Borrador)
            ->and($cotizacion->subtotal)->toBe('200.00')
            ->and($cotizacion->total_iva_16)->toBe('32.00')
            ->and($cotizacion->total)->toBe('232.00')
            ->and($cotizacion->lineas)->toHaveCount(1)
            ->and($cotizacion->lineas[0]->importe)->toBe('200.00')
            ->and($cotizacion->lineas[0]->costo_unitario)->toBeNull();
    });

    it('copia del artículo el costo con descuento vigente', function () {
        $articulo = articuloDe($this->user);

        $this->actingAs($this->user)->post('/cotizaciones', datosCotizacion($this->cliente, [
            lineaLibre(['articulo_id' => $articulo->id, 'modelo' => $articulo->modelo, 'costo_unitario' => '1.00']),
        ]))->assertSessionHasNoErrors();

        expect(Cotizacion::sole()->lineas[0]->costo_unitario)->toBe('90.00');

        $articulo->update(['precio_proveedor' => '200.00']);

        expect(Cotizacion::sole()->lineas[0]->fresh()->costo_unitario)->toBe('90.00');
    });

    it('aplica descuentos de línea y global', function () {
        $this->actingAs($this->user)->post('/cotizaciones', datosCotizacion($this->cliente, [
            lineaLibre(['cantidad' => '3', 'precio_unitario' => '33.33', 'descuento_tipo' => 'porcentaje', 'descuento_valor' => '10']),
        ], ['descuento_global_tipo' => 'monto', 'descuento_global_valor' => '9.99']))->assertSessionHasNoErrors();

        expect(Cotizacion::sole())
            ->total_descuento->toBe('19.99')
            ->total->toBe('92.80');
    });

    it('numera por usuario, de forma consecutiva y sin reutilizar folios', function () {
        $otro = User::factory()->create();
        $clienteOtro = Cliente::factory()->for($otro)->create();

        $this->actingAs($this->user)->post('/cotizaciones', datosCotizacion($this->cliente));
        $this->actingAs($this->user)->post('/cotizaciones', datosCotizacion($this->cliente));
        $this->actingAs($otro)->post('/cotizaciones', datosCotizacion($clienteOtro));

        $this->user->cotizaciones()->where('folio', 2)->sole()->delete();
        $this->actingAs($this->user)->post('/cotizaciones', datosCotizacion($this->cliente));

        expect($this->user->cotizaciones()->orderBy('folio')->pluck('folio')->all())->toBe([1, 3])
            ->and($otro->cotizaciones()->sole()->folio)->toBe(1)
            ->and($this->user->cotizaciones()->where('folio', 3)->sole()->folio_formateado)->toBe('COT-0003');
    });

    it('ignora las filas vacías del formulario', function () {
        $this->actingAs($this->user)->post('/cotizaciones', datosCotizacion($this->cliente, [
            lineaLibre(),
            lineaLibre(['cantidad' => '', 'descripcion' => '', 'precio_unitario' => '']),
        ]))->assertSessionHasNoErrors();

        expect(Cotizacion::sole()->lineas)->toHaveCount(1);
    });

    it('valida los datos', function (array $cambios, string $campo) {
        $datos = datosCotizacion($this->cliente);
        data_set($datos, array_key_first($cambios), reset($cambios));

        $this->actingAs($this->user)->post('/cotizaciones', $datos)->assertSessionHasErrors($campo);

        expect(Cotizacion::count())->toBe(0);
    })->with([
        'sin cliente' => [['cliente_id' => ''], 'cliente_id'],
        'sin líneas' => [['lineas' => []], 'lineas'],
        'cantidad 0' => [['lineas.0.cantidad' => '0'], 'lineas.0.cantidad'],
        'cantidad con decimales' => [['lineas.0.cantidad' => '1.5'], 'lineas.0.cantidad'],
        'precio 0' => [['lineas.0.precio_unitario' => '0'], 'lineas.0.precio_unitario'],
        'precio con 3 decimales' => [['lineas.0.precio_unitario' => '1.005'], 'lineas.0.precio_unitario'],
        'sin descripción' => [['lineas.0.descripcion' => ''], 'lineas.0.descripcion'],
        'tasa inválida' => [['lineas.0.tasa_iva' => '8'], 'lineas.0.tasa_iva'],
        'descuento mayor a 100%' => [['lineas.0' => lineaLibre(['descuento_tipo' => 'porcentaje', 'descuento_valor' => '101'])], 'lineas.0.descuento_valor'],
        'descuento mayor al importe' => [['lineas.0' => lineaLibre(['descuento_tipo' => 'monto', 'descuento_valor' => '200.01'])], 'lineas.0.descuento_valor'],
        'descuento global sin valor' => [['descuento_global_tipo' => 'monto'], 'descuento_global_valor'],
    ]);

    it('rechaza un descuento global por monto mayor a la suma de las líneas', function () {
        $this->actingAs($this->user)->post('/cotizaciones', datosCotizacion($this->cliente, [], [
            'descuento_global_tipo' => 'monto',
            'descuento_global_valor' => '200.01',
        ]))->assertSessionHasErrors('descuento_global_valor');
    });

    it('rechaza el mismo artículo en dos líneas', function () {
        $articulo = articuloDe($this->user);
        $linea = lineaLibre(['articulo_id' => $articulo->id, 'modelo' => 'M-1']);

        $this->actingAs($this->user)->post('/cotizaciones', datosCotizacion($this->cliente, [$linea, $linea]))
            ->assertSessionHasErrors('lineas.1.articulo_id');
    });

    it('rechaza clientes y artículos ajenos', function () {
        $ajeno = User::factory()->create();

        $this->actingAs($this->user)->post('/cotizaciones', datosCotizacion(Cliente::factory()->for($ajeno)->create()))
            ->assertSessionHasErrors('cliente_id');

        $this->actingAs($this->user)->post('/cotizaciones', datosCotizacion($this->cliente, [
            lineaLibre(['articulo_id' => articuloDe($ajeno)->id, 'modelo' => 'X']),
        ]))->assertSessionHasErrors('lineas.0.articulo_id');
    });

    it('conserva las líneas capturadas cuando la validación falla', function () {
        $this->actingAs($this->user)
            ->from('/cotizaciones/crear')
            ->followingRedirects()
            ->post('/cotizaciones', datosCotizacion($this->cliente, [
                lineaLibre(['descripcion' => 'Línea que no se pierde']),
                lineaLibre(['descripcion' => 'Otra', 'precio_unitario' => '0']),
            ]))
            ->assertSee('Línea que no se pierde')
            ->assertSee('value="Otra"', false)
            ->assertSee('aria-invalid="true"', false);
    });
});

describe('sugerencias de artículos', function () {
    it('devuelve solo artículos propios con sus datos de línea', function () {
        articuloDe($this->user, ['nombre' => 'Sello redondo', 'modelo' => 'R-45']);
        articuloDe($this->user, ['nombre' => 'Tinta', 'modelo' => 'T-1', 'objeto_imp' => ObjetoImpuesto::NoObjeto]);
        articuloDe(User::factory()->create(), ['nombre' => 'Sello ajeno']);

        $this->actingAs($this->user)->getJson('/articulos/sugerencias?q=sello')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.nombre', 'Sello redondo')
            ->assertJsonPath('0.precio_unitario', '135.00')
            ->assertJsonPath('0.tasa_iva', '16');

        $this->actingAs($this->user)->getJson('/articulos/sugerencias?q=T-1')->assertJsonPath('0.tasa_iva', 'exento');
        $this->actingAs($this->user)->getJson('/articulos/sugerencias?q=')->assertExactJson([]);
    });
});

describe('edición', function () {
    it('regresa una enviada a borrador y lo avisa', function () {
        $cotizacion = Cotizacion::factory()->for($this->cliente)->conLinea()->enEstado(EstadoCotizacion::Enviada)->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->put("/cotizaciones/{$cotizacion->id}", datosCotizacion($this->cliente))
            ->assertRedirect(route('cotizaciones.show', $cotizacion))
            ->assertSessionHas('exito', fn (string $mensaje) => str_contains($mensaje, 'reenvíala'));

        expect($cotizacion->fresh()->estado)->toBe(EstadoCotizacion::Borrador)
            ->and($cotizacion->fresh()->total)->toBe('232.00');
    });

    it('no permite editar una pagada ni una entregada', function (EstadoCotizacion $estado) {
        $cotizacion = Cotizacion::factory()->for($this->cliente)->conLinea()->enEstado($estado)->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->get("/cotizaciones/{$cotizacion->id}/editar")->assertForbidden();
        $this->actingAs($this->user)->put("/cotizaciones/{$cotizacion->id}", datosCotizacion($this->cliente))->assertForbidden();
    })->with([EstadoCotizacion::Pagada, EstadoCotizacion::ProductoEntregado]);

    it('toma el costo vigente al volver a guardar las líneas', function () {
        $articulo = articuloDe($this->user);
        $linea = lineaLibre(['articulo_id' => $articulo->id, 'modelo' => 'M']);

        $this->actingAs($this->user)->post('/cotizaciones', datosCotizacion($this->cliente, [$linea]));
        $articulo->update(['precio_proveedor' => '200.00']);
        $this->actingAs($this->user)->put('/cotizaciones/'.Cotizacion::sole()->id, datosCotizacion($this->cliente, [$linea]));

        expect(Cotizacion::sole()->lineas[0]->costo_unitario)->toBe('180.00');
    });

    it('acepta un artículo que se eliminó después de guardarse en la cotización', function () {
        $articulo = articuloDe($this->user);
        $linea = lineaLibre(['articulo_id' => $articulo->id, 'modelo' => 'M']);

        $this->actingAs($this->user)->post('/cotizaciones', datosCotizacion($this->cliente, [$linea]));
        $articulo->delete();

        $this->actingAs($this->user)->put('/cotizaciones/'.Cotizacion::sole()->id, datosCotizacion($this->cliente, [$linea]))
            ->assertSessionHasNoErrors();
    });

    it('no deja el total por debajo de lo ya pagado', function () {
        $cotizacion = Cotizacion::factory()->for($this->cliente)->conLinea(10)->enEstado(EstadoCotizacion::Enviada)->create(['user_id' => $this->user->id]);
        $cotizacion->pagos()->create(['tipo' => 'anticipo', 'fecha_pago' => today(), 'monto' => '500.00', 'forma_pago' => '01']);

        $this->actingAs($this->user)->put("/cotizaciones/{$cotizacion->id}", datosCotizacion($this->cliente))
            ->assertSessionHasErrors('lineas');
    });

    it('precarga las líneas guardadas en el formulario', function () {
        $cotizacion = Cotizacion::factory()->for($this->cliente)->conLinea(3, '45.50')->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->get("/cotizaciones/{$cotizacion->id}/editar")
            ->assertOk()
            ->assertSee('value="Sello de prueba"', false)
            ->assertSee('value="45.50"', false);
    });
});

describe('borrado', function () {
    it('elimina una borrador o enviada sin pagos y se lleva sus líneas', function (EstadoCotizacion $estado) {
        $cotizacion = Cotizacion::factory()->for($this->cliente)->conLinea()->enEstado($estado)->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->delete("/cotizaciones/{$cotizacion->id}")->assertRedirect(route('cotizaciones.index'));

        expect(Cotizacion::count())->toBe(0)
            ->and(CotizacionLinea::count())->toBe(0);
    })->with([EstadoCotizacion::Borrador, EstadoCotizacion::Enviada]);

    it('no elimina con pagos ni en pagada o entregada', function (EstadoCotizacion $estado, bool $conPago) {
        $cotizacion = Cotizacion::factory()->for($this->cliente)->conLinea()->enEstado($estado)->create(['user_id' => $this->user->id]);

        if ($conPago) {
            $cotizacion->pagos()->create(['tipo' => 'anticipo', 'fecha_pago' => today(), 'monto' => '10.00', 'forma_pago' => '01']);
        }

        $this->actingAs($this->user)->delete("/cotizaciones/{$cotizacion->id}")->assertSessionHas('error');

        expect(Cotizacion::count())->toBe(1);
    })->with([
        [EstadoCotizacion::Enviada, true],
        [EstadoCotizacion::Pagada, false],
        [EstadoCotizacion::ProductoEntregado, false],
    ]);
});

describe('entregar y duplicar', function () {
    it('marca como entregada solo una pagada', function () {
        $pagada = Cotizacion::factory()->for($this->cliente)->conLinea()->enEstado(EstadoCotizacion::Pagada)->create(['user_id' => $this->user->id]);
        $enviada = Cotizacion::factory()->for($this->cliente)->conLinea()->enEstado(EstadoCotizacion::Enviada)->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->post("/cotizaciones/{$pagada->id}/entregar");
        $this->actingAs($this->user)->post("/cotizaciones/{$enviada->id}/entregar")->assertSessionHas('error');

        expect($pagada->fresh()->estado)->toBe(EstadoCotizacion::ProductoEntregado)
            ->and($enviada->fresh()->estado)->toBe(EstadoCotizacion::Enviada);
    });

    it('duplica en borrador con folio nuevo, mismas líneas y sin pagos', function () {
        $original = Cotizacion::factory()->for($this->cliente)->conLinea(3, '50.00')->enEstado(EstadoCotizacion::Pagada)
            ->create(['user_id' => $this->user->id, 'descuento_global_tipo' => 'porcentaje', 'descuento_global_valor' => '5']);
        $original->lineas()->update(['costo_unitario' => '20.00']);
        $original->pagos()->create(['tipo' => 'pago_total', 'fecha_pago' => today(), 'monto' => '174.00', 'forma_pago' => '03']);

        $this->actingAs($this->user)->post("/cotizaciones/{$original->id}/duplicar");

        $copia = Cotizacion::whereKeyNot($original->id)->sole();

        expect($copia->folio)->toBe($original->folio + 1)
            ->and($copia->estado)->toBe(EstadoCotizacion::Borrador)
            ->and($copia->cliente_id)->toBe($original->cliente_id)
            ->and($copia->descuento_global_valor)->toBe('5.00')
            ->and($copia->total)->toBe($original->total)
            ->and($copia->pagos)->toBeEmpty()
            ->and($copia->lineas)->toHaveCount(1)
            ->and($copia->lineas[0]->cantidad)->toBe(3)
            ->and($copia->lineas[0]->costo_unitario)->toBe('20.00');
    });
});

describe('listado', function () {
    it('muestra solo las cotizaciones propias del mes actual por defecto', function () {
        $propia = Cotizacion::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);
        $vieja = Cotizacion::factory()->for($this->cliente)->create(['user_id' => $this->user->id, 'created_at' => now()->subMonths(2)]);
        $ajena = Cotizacion::factory()->create();

        $this->actingAs($this->user)->get('/cotizaciones')
            ->assertOk()
            ->assertSee($propia->folio_formateado)
            ->assertDontSee($vieja->folio_formateado)
            ->assertSee('aria-current="true"', false)
            ->assertSee('Este mes');

        expect($ajena->user_id)->not->toBe($this->user->id);
    });

    it('filtra por cliente, RFC, folio y estado combinados', function () {
        $otroCliente = Cliente::factory()->for($this->user)->create(['razon_social' => 'PAPELERIA LUNA', 'rfc' => 'PLU010101AB1']);
        $a = Cotizacion::factory()->for($this->cliente)->create(['user_id' => $this->user->id]);
        $b = Cotizacion::factory()->for($otroCliente)->enEstado(EstadoCotizacion::Enviada)->create(['user_id' => $this->user->id]);

        $ver = fn (string $consulta) => $this->actingAs($this->user)->get('/cotizaciones?'.$consulta);

        $ver('cliente=luna')->assertSee($b->folio_formateado)->assertDontSee($a->folio_formateado);
        $ver('rfc=plu010')->assertSee($b->folio_formateado)->assertDontSee($a->folio_formateado);
        $ver('folio=COT-000'.$a->folio)->assertSee($a->folio_formateado)->assertDontSee($b->folio_formateado);
        $ver('folio='.$b->folio)->assertSee($b->folio_formateado)->assertDontSee($a->folio_formateado);
        $ver('estado=enviada&cliente=luna')->assertSee($b->folio_formateado);
        $ver('estado=borrador&cliente=luna')->assertDontSee($b->folio_formateado)->assertSee('Ninguna cotización coincide');
    });

    it('filtra por atajo y por rango en la hora de México', function () {
        $this->travelTo(CarbonImmutable::parse('2026-09-15 12:00', 'America/Mexico_City'));

        // 23:30 del 31 de agosto en México es 1 de septiembre en UTC.
        $finDeAgosto = Cotizacion::factory()->for($this->cliente)->create(['user_id' => $this->user->id, 'created_at' => CarbonImmutable::parse('2026-08-31 23:30', 'America/Mexico_City')->utc()]);
        $hoy = Cotizacion::factory()->for($this->cliente)->create(['user_id' => $this->user->id, 'created_at' => now()]);

        $ver = fn (string $consulta) => $this->actingAs($this->user)->get('/cotizaciones?'.$consulta);

        $ver('')->assertSee($hoy->folio_formateado)->assertDontSee($finDeAgosto->folio_formateado);
        $ver('periodo=hoy')->assertSee($hoy->folio_formateado)->assertDontSee($finDeAgosto->folio_formateado);
        $ver('fecha_desde=2026-08-31&fecha_hasta=2026-08-31')->assertSee($finDeAgosto->folio_formateado)->assertDontSee($hoy->folio_formateado);
        $ver('periodo=hoy&fecha_desde=2026-08-01')->assertSee($finDeAgosto->folio_formateado);

    });

    it('devuelve el fragmento de la búsqueda dinámica', function () {
        $cotizacion = Cotizacion::factory()->for($this->cliente)->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->get('/cotizaciones/buscar', cabecerasAjax())
            ->assertOk()
            ->assertSee('id="cotizaciones-filas"', false)
            ->assertSee('id="cotizaciones-atajos"', false)
            ->assertSee($cotizacion->folio_formateado)
            ->assertDontSee('<html', false);
    });

    it('muestra la papelera solo si se puede eliminar', function () {
        $borrador = Cotizacion::factory()->for($this->cliente)->create(['user_id' => $this->user->id]);
        $pagada = Cotizacion::factory()->for($this->cliente)->enEstado(EstadoCotizacion::Pagada)->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->get('/cotizaciones')
            ->assertSee('Eliminar '.$borrador->folio_formateado)
            ->assertDontSee('Eliminar '.$pagada->folio_formateado);
    });
});

describe('detalle y PDF', function () {
    it('muestra el documento y las acciones según el estado', function () {
        $cotizacion = Cotizacion::factory()->for($this->cliente)->conLinea()->enEstado(EstadoCotizacion::Enviada)->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->get("/cotizaciones/{$cotizacion->id}")
            ->assertOk()
            ->assertSee('Sello de prueba')
            ->assertSee('$116.00')
            ->assertSee('Registrar anticipo')
            ->assertSee('Pago total')
            ->assertDontSee('Registrar saldo')
            ->assertDontSee('Marcar como entregado')
            ->assertSee('data-compartir-cotizacion', false);
    });

    it('genera el PDF con el folio en el nombre', function () {
        $cotizacion = Cotizacion::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);

        $respuesta = $this->actingAs($this->user)->get("/cotizaciones/{$cotizacion->id}/pdf?descargar=1");

        $respuesta->assertOk()->assertHeader('content-type', 'application/pdf');
        expect($respuesta->headers->get('content-disposition'))->toContain('cotizacion-COT-0001.pdf')
            ->and(substr($respuesta->getContent(), 0, 4))->toBe('%PDF');
    });
});
