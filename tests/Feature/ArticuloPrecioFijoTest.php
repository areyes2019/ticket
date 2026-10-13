<?php

use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use Illuminate\Http\UploadedFile;

/*
 * 037: precio fijo capturado a mano (el que lee el cliente), que no se mueve
 * con el costo ni con las utilidades.
 */

beforeEach(function () {
    sembrarCatalogosSat();
    $this->travelTo(now()->setDate(2026, 10, 12));

    $this->catalogo = Catalogo::factory()->conDescuento(10)->conUtilidad(25)->create();
    $this->user = $this->catalogo->user;
});

/**
 * Lo que manda el formulario de artículo.
 *
 * @return array<string, mixed>
 */
function articuloPrecioFijo(Catalogo $catalogo, array $cambios = []): array
{
    return [
        'catalogo_id' => $catalogo->id,
        'nombre' => 'Sello redondo 45 mm',
        'modelo' => 'R-45',
        'clave_prod_serv' => '44121604',
        'clave_unidad' => 'H87',
        'objeto_imp' => '02',
        'precio_proveedor' => '200.00',
        'modo_precio' => 'fijo',
        'precio_fijo' => '330',
        ...$cambios,
    ];
}

function articuloConPrecioFijo(Catalogo $catalogo, string $precioFijo = '330.00', array $cambios = []): Articulo
{
    return Articulo::factory()->for($catalogo)->create(['precio_proveedor' => '200.00', 'precio_fijo' => $precioFijo, ...$cambios]);
}

describe('captura', function () {
    it('guarda el precio fijo, su precio sin IVA y la fecha', function () {
        $this->actingAs($this->user)->post('/articulos', articuloPrecioFijo($this->catalogo))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('exito', fn (string $mensaje) => str_contains($mensaje, 'Precio de venta con IVA: $330.00.'));

        expect(Articulo::sole())
            ->precio_fijo->toBe('330.00')
            ->costo_con_descuento->toBe('180.00')
            ->precio_unitario_sin_iva->toBe('284.48')
            ->precio_unitario_con_iva->toBe(330.0)
            ->precio_fijo_desde->toDateString()->toBe('2026-10-12');
    });

    it('sube un centavo el precio con IVA que no se puede alcanzar exacto', function () {
        $this->actingAs($this->user)->post('/articulos', articuloPrecioFijo($this->catalogo, ['precio_fijo' => '7']));

        expect(Articulo::sole())->precio_unitario_sin_iva->toBe('6.04')->precio_unitario_con_iva->toBe(7.01);
    });

    it('sin IVA guarda el precio tal cual, con centavos', function () {
        $this->actingAs($this->user)->post('/articulos', articuloPrecioFijo($this->catalogo, ['objeto_imp' => '01', 'precio_fijo' => '329.50']));

        expect(Articulo::sole())->precio_unitario_sin_iva->toBe('329.50');
    });

    it('valida el precio fijo', function (array $cambios, string $error) {
        $this->actingAs($this->user)->post('/articulos', articuloPrecioFijo($this->catalogo, $cambios))
            ->assertSessionHasErrors([array_key_first($cambios) => $error]);

        expect(Articulo::count())->toBe(0);
    })->with([
        'vacío' => [['precio_fijo' => ''], 'Escribe el precio fijo.'],
        'cero' => [['precio_fijo' => '0'], 'El precio fijo debe estar entre $0.01 y $10,000,000.00.'],
        'arriba del tope' => [['precio_fijo' => '10000000.01'], 'El precio fijo debe estar entre $0.01 y $10,000,000.00.'],
        'tres decimales' => [['precio_fijo' => '1.234'], 'El precio fijo admite como máximo 2 decimales.'],
        'modo desconocido' => [['modo_precio' => 'otro'], 'Elige cómo se calcula el precio de venta.'],
    ]);

    it('acepta el tope', function () {
        $this->actingAs($this->user)->post('/articulos', articuloPrecioFijo($this->catalogo, ['precio_fijo' => '10000000']))
            ->assertSessionHasNoErrors();
    });

    it('por utilidad (o sin modo) descarta el precio fijo aunque venga lleno', function (?string $modo) {
        $this->actingAs($this->user)->post('/articulos', articuloPrecioFijo($this->catalogo, ['modo_precio' => $modo, 'precio_fijo' => 'no es número']))
            ->assertSessionHasNoErrors();

        expect(Articulo::sole())
            ->precio_fijo->toBeNull()
            ->precio_fijo_desde->toBeNull()
            ->precio_unitario_sin_iva->toBe('225.00');
    })->with(['utilidad' => 'utilidad', 'sin modo' => null]);
});

describe('qué mueve un precio fijo', function () {
    it('no cambia con el precio de lista, el descuento ni la utilidad del catálogo, pero sí el costo', function () {
        $articulo = articuloConPrecioFijo($this->catalogo);

        $articulo->update(['precio_proveedor' => '300.00', 'utilidad_porcentaje' => '80']);
        expect($articulo->fresh())->costo_con_descuento->toBe('270.00')->precio_unitario_sin_iva->toBe('284.48');

        $this->catalogo->update(['descuento' => 50, 'utilidad_porcentaje' => 90]);
        expect($articulo->fresh())->costo_con_descuento->toBe('150.00')->precio_unitario_sin_iva->toBe('284.48');
    });

    it('al cambiar el objeto de impuesto conserva el precio que lee el cliente', function () {
        $articulo = articuloConPrecioFijo($this->catalogo);

        $articulo->update(['objeto_imp' => '01']);
        expect($articulo->fresh())->precio_unitario_sin_iva->toBe('330.00');

        $articulo->update(['objeto_imp' => '02']);
        expect($articulo->fresh())->precio_unitario_sin_iva->toBe('284.48');
    });

    it('volver a utilidad recalcula con la propia conservada o con la del catálogo', function (?string $propia, string $esperado) {
        $articulo = articuloConPrecioFijo($this->catalogo, cambios: ['utilidad_porcentaje' => $propia]);

        $this->actingAs($this->user)->put("/articulos/{$articulo->id}", articuloPrecioFijo($this->catalogo, ['modo_precio' => 'utilidad', 'utilidad_porcentaje' => $propia]))
            ->assertSessionHasNoErrors();

        expect($articulo->fresh())
            ->precio_fijo->toBeNull()
            ->precio_fijo_desde->toBeNull()
            ->utilidad_porcentaje->toBe($propia === null ? null : '50.00')
            ->precio_unitario_sin_iva->toBe($esperado);
    })->with([
        'propia 50%' => ['50', '270.69'],
        'hereda 25%' => [null, '225.00'],
    ]);

    it('la fecha solo se mueve cuando cambia el precio fijo', function () {
        $articulo = articuloConPrecioFijo($this->catalogo);

        $this->travelTo(now()->setDate(2026, 11, 1));
        $articulo->update(['nombre' => 'Otro nombre', 'precio_fijo' => '330']);
        expect($articulo->fresh()->precio_fijo_desde->toDateString())->toBe('2026-10-12');

        $articulo->update(['precio_fijo' => '340']);
        expect($articulo->fresh()->precio_fijo_desde->toDateString())->toBe('2026-11-01');
    });
});

describe('formulario y listado', function () {
    it('la edición abre en modo fijo con la fecha y la utilidad que deja', function () {
        $articulo = articuloConPrecioFijo($this->catalogo);

        $this->actingAs($this->user)->get("/articulos/{$articulo->id}/editar")
            ->assertOk()
            ->assertSee('id="modo_precio-fijo" type="radio" name="modo_precio" value="fijo" checked', false)
            ->assertSee('Precio fijo con IVA')
            ->assertSee('Precio fijo desde el 12/10/2026')
            ->assertSee('data-fijo="precio_fijo"', false)
            ->assertSee('58.04%')
            ->assertSee('+$104.48');
    });

    it('el alta abre en modo utilidad y sin fecha', function () {
        $this->actingAs($this->user)->get('/articulos/crear')
            ->assertOk()
            ->assertSee('value="utilidad" checked', false)
            ->assertDontSee('Precio fijo desde');
    });

    it('un precio fijo debajo del costo se guarda, avisa y sale en rojo en el listado', function () {
        $abajo = articuloConPrecioFijo($this->catalogo, cambios: ['precio_proveedor' => '400.00']);
        Articulo::factory()->for($this->catalogo)->create();

        expect($abajo->fresh()->por_debajo_del_costo)->toBeTrue();

        $this->actingAs($this->user)->get("/articulos/{$abajo->id}/editar")
            ->assertSee('−$75.52')
            ->assertSee('-20.98%')
            ->assertSee('$75.52</span> por debajo del costo', false);

        $this->get('/articulos')
            ->assertSee('class="numero precio-bajo-costo"', false)
            ->assertSee('title="Por debajo del costo"', false);
        expect(substr_count($this->get('/articulos')->getContent(), 'precio-bajo-costo'))->toBe(1);
    });

    it('marca "Fijo" solo en los artículos con precio fijo', function () {
        articuloConPrecioFijo($this->catalogo);
        Articulo::factory()->for($this->catalogo)->create();

        expect(substr_count($this->actingAs($this->user)->get('/articulos')->getContent(), 'etiqueta-fijo">Fijo'))->toBe(1);
    });
});

describe('recálculo del catálogo', function () {
    function cambioDeCatalogo(Catalogo $catalogo, array $cambios = []): array
    {
        return ['nombre' => $catalogo->nombre, 'descuento' => '10', 'utilidad_porcentaje' => '25', 'utilidad_distribuidor_porcentaje' => '0', ...$cambios];
    }

    it('la confirmación dice cuántos precios fijos se conservan', function () {
        Articulo::factory()->for($this->catalogo)->count(2)->create();
        articuloConPrecioFijo($this->catalogo);

        // El fijo también cuenta en N: el descuento mueve su costo y con él su precio distribuidor.
        $this->actingAs($this->user)->put("/catalogos/{$this->catalogo->id}", cambioDeCatalogo($this->catalogo, ['descuento' => '20']))
            ->assertSessionHas('confirmar_recalculo', 3)
            ->assertSessionHas('conservan_precio_fijo', 1);

        $this->get("/catalogos/{$this->catalogo->id}/editar")
            ->assertSee('y <strong>1</strong>', false)
            ->assertSee('artículo con precio fijo conservará su precio');

        $this->put("/catalogos/{$this->catalogo->id}", cambioDeCatalogo($this->catalogo, ['descuento' => '20', 'confirmar' => '1']))
            ->assertSessionHas('exito', 'Catálogo actualizado. Se recalculó el precio de 3 artículos. 1 artículo con precio fijo conservó su precio.');
    });

    it('un cambio de la utilidad distribuidor no cuenta precios fijos', function () {
        articuloConPrecioFijo($this->catalogo);

        $this->actingAs($this->user)->put("/catalogos/{$this->catalogo->id}", cambioDeCatalogo($this->catalogo, ['utilidad_distribuidor_porcentaje' => '30']))
            ->assertSessionHas('confirmar_recalculo', 1)
            ->assertSessionHas('conservan_precio_fijo', 0);
    });

    it('solo con precios fijos no pide confirmación y lo dice al guardar', function () {
        articuloConPrecioFijo($this->catalogo);
        articuloConPrecioFijo($this->catalogo, '500.00');

        // Utilidad distribuidor del catálogo en 0: el precio distribuidor sigue en el costo, que sí cambia.
        $this->actingAs($this->user)->put("/catalogos/{$this->catalogo->id}", cambioDeCatalogo($this->catalogo, ['utilidad_porcentaje' => '60']))
            ->assertSessionMissing('confirmar_recalculo')
            ->assertSessionHas('exito', 'Catálogo actualizado. 2 artículos con precio fijo conservaron su precio.');

        expect($this->catalogo->fresh()->utilidad_porcentaje)->toBe('60.00');
    });

    it('un precio fijo cuenta como afectado solo si cambia su precio distribuidor', function () {
        $articulo = articuloConPrecioFijo($this->catalogo);

        expect($this->catalogo->articulosAfectados(10, 60, 0))->toBe(0)
            ->and($this->catalogo->articulosAfectados(10, 25, 30))->toBe(1)
            ->and($articulo->fresh()->precio_unitario_sin_iva)->toBe('284.48');
    });
});

describe('clonar, CSV y documentos', function () {
    it('el clon copia el precio fijo con la fecha del día en que se clona', function () {
        $articulo = articuloConPrecioFijo($this->catalogo);
        $this->travelTo(now()->setDate(2026, 11, 5));

        $this->actingAs($this->user)->post("/articulos/{$articulo->id}/clonar");

        expect(Articulo::whereKeyNot($articulo->id)->sole())
            ->precio_fijo->toBe('330.00')
            ->precio_unitario_sin_iva->toBe('284.48')
            ->precio_fijo_desde->toDateString()->toBe('2026-11-05');
    });

    it('importa la columna precio_fijo llena, vacía o ausente y rechaza un valor inválido', function () {
        $archivo = UploadedFile::fake()->createWithContent('articulos.csv',
            "nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor,utilidad_porcentaje,utilidad_distribuidor_porcentaje,precio_fijo\n"
            ."Fijo,F-1,44121604,H87,02,200,,,330\n"
            ."Por utilidad,U-1,44121604,H87,02,200,,,\n"
            ."Inválido,X-1,44121604,H87,02,200,,,0\n");

        $this->actingAs($this->user)->followingRedirects()
            ->post('/articulos/importar', ['catalogo_id' => $this->catalogo->id, 'archivo' => $archivo])
            ->assertSee('2 artículos importados.')
            ->assertSee('El precio fijo debe estar entre $0.01 y $10,000,000.00.');

        expect(Articulo::firstWhere('modelo', 'F-1'))->precio_fijo->toBe('330.00')->precio_unitario_sin_iva->toBe('284.48')
            ->and(Articulo::firstWhere('modelo', 'U-1'))->precio_fijo->toBeNull()->precio_unitario_sin_iva->toBe('225.00');
    });

    it('exporta el precio fijo o lo deja vacío', function () {
        articuloConPrecioFijo($this->catalogo, cambios: ['nombre' => 'Fijo', 'modelo' => 'F-1']);
        Articulo::factory()->for($this->catalogo)->create(['nombre' => 'Por utilidad', 'modelo' => 'U-1', 'precio_proveedor' => '200.00']);

        $csv = $this->actingAs($this->user)->get('/articulos/exportar')->streamedContent();

        expect($csv)->toContain(',utilidad_distribuidor_porcentaje,precio_fijo')
            ->toContain('Fijo,F-1,44121600,H87,02,200.00,,,330.00')
            ->toContain('"Por utilidad",U-1,44121600,H87,02,200.00,,,'.'
');
    });

    it('las líneas nuevas toman el precio fijo y las guardadas no cambian', function () {
        $articulo = Articulo::factory()->for($this->catalogo)->create(['nombre' => 'Sello', 'precio_proveedor' => '200.00']);
        $cotizacion = Cotizacion::factory()->for(Cliente::factory()->for($this->user))->create(['user_id' => $this->user->id]);
        agregarLinea($cotizacion, $articulo, 1);
        $guardado = $cotizacion->lineas()->sole()->precio_unitario;

        $articulo->update(['precio_fijo' => '330']);

        $this->actingAs($this->user)->getJson('/articulos/sugerencias?q=Sello')
            ->assertJsonPath('0.precio_unitario', '284.48');
        expect($cotizacion->lineas()->sole()->precio_unitario)->toBe($guardado);
    });
});
