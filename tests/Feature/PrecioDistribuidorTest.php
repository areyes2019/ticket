<?php

use App\Enums\EstadoCotizacion;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Factura;
use App\Models\User;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use Illuminate\Support\Facades\DB;

/*
 * 028: precio distribuidor (mismo costo, su propia utilidad) y cliente
 * distribuidor que lo usa en cotizaciones y facturas.
 */

/**
 * Lo que manda el formulario de artículo.
 *
 * @return array<string, mixed>
 */
function articuloDistribuidor(Catalogo $catalogo, array $cambios = []): array
{
    return [
        'catalogo_id' => $catalogo->id,
        'nombre' => 'Sello redondo 45 mm',
        'modelo' => 'R-45',
        'clave_prod_serv' => '44121604',
        'clave_unidad' => 'H87',
        'objeto_imp' => '02',
        'precio_proveedor' => '100.00',
        ...$cambios,
    ];
}

/**
 * Lo que manda el formulario de cliente.
 *
 * @return array<string, mixed>
 */
function clienteDistribuidor(array $cambios = []): array
{
    return ['rfc' => 'ADE010101AB1', 'razon_social' => 'ACEROS DEL NORTE', 'regimen_fiscal' => '601', 'codigo_postal_fiscal' => '20000', ...$cambios];
}

/**
 * Cotización con una línea del artículo a su precio directo.
 */
function cotizacionDistribuidor(User $user, Cliente $cliente, Articulo $articulo, EstadoCotizacion $estado = EstadoCotizacion::Enviada): Cotizacion
{
    $cotizacion = Cotizacion::factory()->for($cliente)->enEstado($estado)->create(['user_id' => $user->id]);
    $linea = ['cantidad' => 2, 'precio_unitario' => $articulo->precio_unitario_sin_iva, 'tasa_iva' => '16', 'descuento_tipo' => null, 'descuento_valor' => null];
    $totales = CalculadoraTotalesDocumento::calcular([$linea]);

    $cotizacion->lineas()->create([
        ...$linea,
        'orden' => 1,
        'articulo_id' => $articulo->id,
        'descripcion' => $articulo->nombre,
        'modelo' => $articulo->modelo,
        'importe' => $totales['lineas'][0]['importe'],
        'iva_importe' => $totales['lineas'][0]['iva_importe'],
    ]);
    $cotizacion->aplicarTotales($totales);
    $cotizacion->saveQuietly();

    return $cotizacion;
}

describe('cálculo', function () {
    it('calcula los dos precios del ejemplo de la spec', function () {
        $catalogo = Catalogo::factory()->conUtilidad(50)->create(['utilidad_distribuidor_porcentaje' => 25]);
        $articulo = Articulo::factory()->for($catalogo)->create(['precio_proveedor' => '200.00']);

        expect($articulo->fresh())
            ->precio_unitario_sin_iva->toBe('300.00')
            ->precio_distribuidor_sin_iva->toBe('250.00')
            ->precio_unitario_con_iva->toBe(348.0)
            ->precio_distribuidor_con_iva->toBe(290.0);

        $articulo->update(['utilidad_distribuidor_porcentaje' => 30]);

        // $302 con IVA es inalcanzable: sube a $303, como el precio directo (025).
        expect($articulo->fresh())
            ->precio_distribuidor_sin_iva->toBe('261.21')
            ->precio_distribuidor_con_iva->toBe(303.0)
            ->utilidad_distribuidor_porcentaje_efectivo->toBe('30.00');
    });

    it('no lleva IVA en un artículo que no es objeto de impuesto', function () {
        $catalogo = Catalogo::factory()->create(['utilidad_distribuidor_porcentaje' => 30]);
        $articulo = Articulo::factory()->for($catalogo)->create(['precio_proveedor' => '200.00', 'objeto_imp' => '01']);

        expect($articulo->fresh())->precio_distribuidor_sin_iva->toBe('260.00')->precio_distribuidor_con_iva->toBe(260.0);
    });

    it('guarda la utilidad distribuidor propia del formulario y avisa ambos precios', function () {
        sembrarCatalogosSat();
        $catalogo = Catalogo::factory()->conUtilidad(50)->create(['utilidad_distribuidor_porcentaje' => 10]);

        $this->actingAs($catalogo->user)
            ->post('/articulos', articuloDistribuidor($catalogo, ['precio_proveedor' => '200.00', 'utilidad_distribuidor_porcentaje' => '25', 'precio_distribuidor_sin_iva' => '1.00']))
            ->assertSessionHas('exito', 'Artículo creado. Precio de venta con IVA: $348.00. Precio distribuidor con IVA: $290.00.');

        expect(Articulo::firstOrFail())->utilidad_distribuidor_porcentaje->toBe('25.00')->precio_distribuidor_sin_iva->toBe('250.00');
    });

    it('valida la utilidad distribuidor como la utilidad', function (string $valor, bool $valida) {
        sembrarCatalogosSat();
        $catalogo = Catalogo::factory()->create();

        $respuesta = $this->actingAs($catalogo->user)->post('/articulos', articuloDistribuidor($catalogo, ['utilidad_distribuidor_porcentaje' => $valor]));

        $valida ? $respuesta->assertSessionHasNoErrors() : $respuesta->assertSessionHasErrors('utilidad_distribuidor_porcentaje');
    })->with([
        'negativa' => ['-1', false],
        'mayor a 999.99' => ['1000', false],
        'tres decimales' => ['10.555', false],
        'cero' => ['0', true],
        'tres dígitos' => ['450', true],
    ]);

    it('muestra la cadena distribuidor en el formulario con el placeholder heredado', function () {
        sembrarCatalogosSat();
        $catalogo = Catalogo::factory()->conUtilidad(50)->create(['utilidad_distribuidor_porcentaje' => 25]);
        $articulo = Articulo::factory()->for($catalogo)->create(['precio_proveedor' => '200.00']);

        $this->actingAs($articulo->user)
            ->get("/articulos/{$articulo->id}/editar")
            ->assertOk()
            ->assertSee('placeholder="Hereda 25% del catálogo"', false)
            ->assertSee('data-herencia="utilidad_distribuidor"', false)
            ->assertSee('data-aviso-utilidad="utilidad_distribuidor_porcentaje-aviso"', false)
            ->assertSeeInOrder(['Utilidad distribuidor', '25%', '+$50.00', 'Precio distribuidor sin IVA', '$250.00', 'Precio distribuidor final', '$290.00']);
    });
});

describe('recálculo del catálogo', function () {
    beforeEach(function () {
        $this->catalogo = Catalogo::factory()->conUtilidad(50)->create(['utilidad_distribuidor_porcentaje' => 25]);
        $this->hereda = Articulo::factory()->for($this->catalogo)->create(['precio_proveedor' => '200.00']);
        $this->propia = Articulo::factory()->for($this->catalogo)->create(['precio_proveedor' => '200.00', 'utilidad_porcentaje' => 50, 'utilidad_distribuidor_porcentaje' => 25]);
    });

    it('mueve solo el precio distribuidor de los que heredan la utilidad distribuidor', function () {
        $this->catalogo->update(['utilidad_distribuidor_porcentaje' => 30]);

        expect($this->hereda->fresh())->precio_distribuidor_sin_iva->toBe('261.21')->precio_unitario_sin_iva->toBe('300.00')
            ->and($this->propia->fresh())->precio_distribuidor_sin_iva->toBe('250.00');
    });

    it('cambiar la utilidad directo no toca el precio distribuidor', function () {
        $this->catalogo->update(['utilidad_porcentaje' => 30]);

        expect($this->hereda->fresh())->precio_unitario_sin_iva->toBe('261.21')->precio_distribuidor_sin_iva->toBe('250.00');
    });

    it('cambiar el descuento mueve los dos precios de todos', function () {
        $this->catalogo->update(['descuento' => 10]);

        foreach ([$this->hereda, $this->propia] as $articulo) {
            expect($articulo->fresh())->costo_con_descuento->toBe('180.00')
                ->precio_unitario_sin_iva->toBe('270.69')
                ->precio_distribuidor_sin_iva->toBe('225.00');
        }
    });

    it('cuenta los artículos que cambiaría la utilidad distribuidor y pide confirmación', function () {
        expect($this->catalogo->articulosAfectados('0', '50', '30'))->toBe(1)
            ->and($this->catalogo->articulosAfectados('0', '50', '25'))->toBe(0);

        $this->actingAs($this->catalogo->user)
            ->put("/catalogos/{$this->catalogo->id}", ['nombre' => $this->catalogo->nombre, 'descuento' => '0', 'utilidad_porcentaje' => '50', 'utilidad_distribuidor_porcentaje' => '30'])
            ->assertSessionHas('confirmar_recalculo', 1);

        expect($this->hereda->fresh()->precio_distribuidor_sin_iva)->toBe('250.00');
    });

    it('valida y guarda la utilidad distribuidor del catálogo; vacía vale 0', function () {
        $this->actingAs($this->catalogo->user)
            ->put("/catalogos/{$this->catalogo->id}", ['nombre' => 'X', 'descuento' => '0', 'utilidad_porcentaje' => '50', 'utilidad_distribuidor_porcentaje' => '1000'])
            ->assertSessionHasErrors(['utilidad_distribuidor_porcentaje' => 'La utilidad distribuidor debe estar entre 0 y 999.99%.']);

        $this->put("/catalogos/{$this->catalogo->id}", ['nombre' => 'X', 'descuento' => '0', 'utilidad_porcentaje' => '50', 'utilidad_distribuidor_porcentaje' => '', 'confirmar' => '1'])
            ->assertSessionHasNoErrors();

        expect($this->catalogo->fresh()->utilidad_distribuidor_porcentaje)->toBe('0.00')
            ->and($this->hereda->fresh()->precio_distribuidor_sin_iva)->toBe('200.00');
    });
});

describe('listado y ficha', function () {
    it('muestra la columna, la ordena y lleva el precio a la ficha', function (string $direccion, array $orden) {
        $catalogo = Catalogo::factory()->create();
        Articulo::factory()->for($catalogo)->create(['nombre' => 'Barato', 'precio_proveedor' => '100.00', 'utilidad_distribuidor_porcentaje' => 10]);
        Articulo::factory()->for($catalogo)->create(['nombre' => 'Caro', 'precio_proveedor' => '100.00', 'utilidad_distribuidor_porcentaje' => 90]);

        $this->actingAs($catalogo->user)
            ->get("/articulos?orden=distribuidor&direccion={$direccion}")
            ->assertOk()
            ->assertSee('Precio distribuidor')
            ->assertSee('data-precio-distribuidor="$129.00"', false)
            ->assertSee('data-ficha-compartir="distribuidor"', false)
            ->assertSeeInOrder($orden);
    })->with([
        'ascendente' => ['asc', ['Barato', 'Caro']],
        'descendente' => ['desc', ['Caro', 'Barato']],
    ]);

    it('manda el precio distribuidor en las sugerencias de venta, no en las de costo', function () {
        $articulo = Articulo::factory()->for(Catalogo::factory()->create(['utilidad_distribuidor_porcentaje' => 25]))->create(['nombre' => 'Sello', 'precio_proveedor' => '200.00']);

        $this->actingAs($articulo->user)->getJson('/articulos/sugerencias?q=Sello')
            ->assertJsonPath('0.precio_distribuidor', '250.00');

        expect($this->getJson('/articulos/sugerencias?q=Sello&precio=costo')->json('0'))->not->toHaveKey('precio_distribuidor');
    });
});

describe('migración', function () {
    it('rellena el precio distribuidor de todos los artículos con la utilidad heredada', function () {
        $catalogo = Catalogo::factory()->conUtilidad(50)->create();
        $articulo = Articulo::factory()->for($catalogo)->create(['precio_proveedor' => '6.00']);
        $eliminado = Articulo::factory()->for($catalogo)->create(['precio_proveedor' => '200.00']);
        $eliminado->delete();
        DB::table('articulos')->update(['precio_distribuidor_sin_iva' => 0]);

        $migracion = require database_path('migrations/2026_10_12_100000_precio_distribuidor.php');
        $migracion->down();
        $migracion->up();

        // Con 0% el precio distribuidor es el costo llevado al peso entero.
        expect(DB::table('articulos')->orderBy('id')->pluck('precio_distribuidor_sin_iva')->map(fn ($precio) => number_format((float) $precio, 2, '.', ''))->all())
            ->toBe(['6.90', '200.00'])
            ->and(Catalogo::firstOrFail()->utilidad_distribuidor_porcentaje)->toBe('0.00')
            ->and($articulo->fresh()->utilidad_distribuidor_porcentaje)->toBeNull();
    });
});

describe('cliente distribuidor', function () {
    it('guarda la marca, ausente o vacía es no distribuidor', function (array $datos, bool $esperado) {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/clientes', clienteDistribuidor($datos))->assertSessionHasNoErrors();

        expect(Cliente::firstOrFail()->es_distribuidor)->toBe($esperado);
    })->with([
        'marcada' => [['es_distribuidor' => '1'], true],
        'ausente' => [[], false],
        'vacía' => [['es_distribuidor' => ''], false],
    ]);

    it('se desmarca al editar y rechaza un valor que no es booleano', function () {
        $cliente = Cliente::factory()->create(['es_distribuidor' => true]);

        $this->actingAs($cliente->user)->put("/clientes/{$cliente->id}", clienteDistribuidor(['rfc' => $cliente->rfc, 'es_distribuidor' => 'quizá']))
            ->assertSessionHasErrors('es_distribuidor');

        $this->put("/clientes/{$cliente->id}", clienteDistribuidor(['rfc' => $cliente->rfc]))->assertSessionHasNoErrors();

        expect($cliente->fresh()->es_distribuidor)->toBeFalse();
    });

    it('no deja marcar el cliente de otro usuario', function () {
        $ajeno = Cliente::factory()->create();

        $this->actingAs(User::factory()->create())
            ->put("/clientes/{$ajeno->id}", clienteDistribuidor(['rfc' => $ajeno->rfc, 'es_distribuidor' => '1']))
            ->assertNotFound();

        expect($ajeno->fresh()->es_distribuidor)->toBeFalse();
    });

    it('muestra la casilla y la insignia en el listado', function () {
        $distribuidor = Cliente::factory()->create(['razon_social' => 'FERRETERIA LOPEZ', 'es_distribuidor' => true]);

        $this->actingAs($distribuidor->user)->get("/clientes/{$distribuidor->id}/editar")
            ->assertSee('name="es_distribuidor" value="1" checked', false)
            ->assertSee('Sus cotizaciones y facturas usarán el precio distribuidor');

        $this->get('/clientes')->assertSee('<span class="etiqueta etiqueta-activa">Distribuidor</span>', false);
    });
});

describe('documentos', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->articulo = articuloFacturable($this->user);
        $this->articulo->catalogo->update(['utilidad_distribuidor_porcentaje' => 20]);
        $this->articulo->refresh();
        $this->distribuidor = Cliente::factory()->for($this->user)->create(['razon_social' => 'FERRETERIA LOPEZ', 'es_distribuidor' => true]);
        $this->comun = Cliente::factory()->for($this->user)->create(['razon_social' => 'PAPELERIA SOL']);
    });

    it('la cotización lleva los distribuidores y los dos precios de cada línea guardada', function () {
        $cotizacion = cotizacionDistribuidor($this->user, $this->distribuidor, $this->articulo, EstadoCotizacion::Borrador);

        $this->actingAs($this->user)->get("/cotizaciones/{$cotizacion->id}/editar")
            ->assertOk()
            ->assertSee('data-clientes-distribuidores="'.e(json_encode([$this->distribuidor->id => 'FERRETERIA LOPEZ'])).'"', false)
            ->assertSee('FERRETERIA LOPEZ</strong> es distribuidor: cada línea usa el precio distribuidor.', false)
            ->assertSee('Puedes cambiarlo línea por línea')
            ->assertSee('data-precio-directo="'.$this->articulo->precio_unitario_sin_iva.'" data-precio-distribuidor="'.$this->articulo->precio_distribuidor_sin_iva.'"', false);
    });

    it('la cotización de un cliente común esconde el aviso', function () {
        $this->actingAs($this->user)->get('/cotizaciones/crear')
            ->assertSee('data-aviso-distribuidor', false)
            ->assertSee('alerta alerta-info" hidden', false);
    });

    it('la ventana del dashboard también activa el precio distribuidor', function () {
        $this->actingAs($this->user)->get('/dashboard')->assertSee('data-aviso-distribuidor', false);
    });

    it('la factura desde cero activa el precio distribuidor', function () {
        $this->actingAs($this->user)->get('/facturas/crear')
            ->assertOk()
            ->assertSee('data-aviso-distribuidor', false)
            ->assertDontSee('Puedes cambiarlo línea por línea');
    });

    it('la factura de una cotización conserva el precio cotizado', function () {
        $cotizacion = cotizacionDistribuidor($this->user, $this->distribuidor, $this->articulo);

        $this->actingAs($this->user)->get("/facturas/crear?cotizacion={$cotizacion->id}")
            ->assertOk()
            ->assertDontSee('data-aviso-distribuidor', false);
    });

    it('la corrección de una factura que viene de una cotización tampoco lo activa', function () {
        $cotizacion = cotizacionDistribuidor($this->user, $this->distribuidor, $this->articulo);
        $factura = Factura::factory()->for($this->distribuidor)->create(['user_id' => $this->user->id, 'cotizacion_id' => $cotizacion->id]);

        $this->actingAs($this->user)->get("/facturas/{$factura->id}/editar")
            ->assertOk()
            ->assertDontSee('data-aviso-distribuidor', false);
    });

    it('no pinta precios de artículos ajenos en las líneas de un intento fallido', function () {
        $ajeno = articuloFacturable(User::factory()->create());

        $this->actingAs($this->user)->from('/cotizaciones/crear')
            ->post('/cotizaciones', ['cliente_id' => '', 'lineas' => [['articulo_id' => (string) $ajeno->id, 'cantidad' => '1', 'descripcion' => 'X', 'precio_unitario' => '1', 'tasa_iva' => '16']]])
            ->assertSessionHasErrors();

        $this->get('/cotizaciones/crear')->assertDontSee('data-precio-directo', false);
    });

    it('marcar un cliente como distribuidor no mueve sus documentos guardados', function () {
        $cotizacion = cotizacionDistribuidor($this->user, $this->comun, $this->articulo);
        $antes = $cotizacion->lineas()->value('precio_unitario');

        $this->actingAs($this->user)->put("/clientes/{$this->comun->id}", clienteDistribuidor(['rfc' => $this->comun->rfc, 'es_distribuidor' => '1']));

        expect($cotizacion->lineas()->value('precio_unitario'))->toBe($antes);
    });
});
