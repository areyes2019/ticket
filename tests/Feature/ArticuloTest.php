<?php

use App\Enums\ObjetoImpuesto;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    sembrarCatalogosSat();
});

/**
 * @param  array<string, mixed>  $cambios
 * @return array<string, mixed>
 */
function datosArticulo(Catalogo $catalogo, array $cambios = []): array
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

describe('acceso', function () {
    it('pide iniciar sesión', function (string $ruta) {
        $this->get($ruta)->assertRedirect(route('login'));
    })->with(['/articulos', '/articulos/crear', '/articulos/importar', '/articulos/exportar']);

    it('responde 401 a las peticiones AJAX sin sesión', function (string $ruta) {
        $this->get($ruta, cabecerasAjax())->assertUnauthorized();
    })->with(['/articulos/buscar', '/catalogos-sat/claves-prod-serv?q=sello', '/catalogos-sat/claves-unidad?q=pieza']);

    it('saca a un usuario suspendido', function () {
        $this->actingAs(User::factory()->suspendido()->create())
            ->get('/articulos')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    });

    it('muestra el enlace de artículos en el menú', function () {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertSee(route('articulos.index'))
            ->assertSee('bi-box-seam', false);
    });
});

describe('alta', function () {
    it('crea un artículo con su precio de venta calculado', function () {
        $catalogo = Catalogo::factory()->conDescuento(10)->conUtilidad(25)->create();

        $this->actingAs($catalogo->user)
            ->post('/articulos', datosArticulo($catalogo, ['precio_proveedor' => '200.00']))
            ->assertRedirect(route('articulos.index'))
            ->assertSessionHas('exito', 'Artículo creado. Precio de venta con IVA: $261.00. Precio distribuidor con IVA: $209.00.');

        $articulo = Articulo::sole();

        expect($articulo->user_id)->toBe($catalogo->user_id)
            ->and($articulo->catalogo_id)->toBe($catalogo->id)
            ->and($articulo->proveedor_id)->toBe($catalogo->proveedor_id)
            ->and($articulo->objeto_imp)->toBe(ObjetoImpuesto::SiObjeto)
            ->and($articulo->precio_proveedor)->toBe('200.00')
            ->and($articulo->utilidad_porcentaje)->toBeNull()
            ->and($articulo->costo_con_descuento)->toBe('180.00')
            ->and($articulo->precio_unitario_sin_iva)->toBe('225.00')
            ->and($articulo->utilidad)->toBe(45.0);
    });

    it('guarda la clave de unidad en mayúsculas', function () {
        $catalogo = Catalogo::factory()->create();

        $this->actingAs($catalogo->user)->post('/articulos', datosArticulo($catalogo, ['clave_unidad' => ' h87 ']));

        expect(Articulo::sole()->clave_unidad)->toBe('H87');
    });

    it('no permite asignar el usuario desde el formulario', function () {
        $catalogo = Catalogo::factory()->create();
        $otro = User::factory()->create();

        $this->actingAs($catalogo->user)->post('/articulos', datosArticulo($catalogo, ['user_id' => $otro->id]));

        expect(Articulo::sole()->user_id)->toBe($catalogo->user_id);
    });

    it('exige los campos obligatorios', function (string $campo) {
        $catalogo = Catalogo::factory()->create();

        $this->actingAs($catalogo->user)
            ->post('/articulos', datosArticulo($catalogo, [$campo => '']))
            ->assertSessionHasErrors($campo);

        expect(Articulo::count())->toBe(0);
    })->with(['catalogo_id', 'nombre', 'modelo', 'clave_prod_serv', 'clave_unidad', 'objeto_imp', 'precio_proveedor']);

    it('rechaza claves SAT que no están en el catálogo', function (string $campo, string $valor) {
        $catalogo = Catalogo::factory()->create();

        $this->actingAs($catalogo->user)
            ->post('/articulos', datosArticulo($catalogo, [$campo => $valor]))
            ->assertSessionHasErrors($campo);
    })->with([
        'producto/servicio' => ['clave_prod_serv', '99999999'],
        'unidad' => ['clave_unidad', 'XYZ'],
        'objeto de impuesto' => ['objeto_imp', '05'],
    ]);

    it('rechaza un precio del proveedor inválido', function (string $precio) {
        $catalogo = Catalogo::factory()->create();

        $this->actingAs($catalogo->user)
            ->post('/articulos', datosArticulo($catalogo, ['precio_proveedor' => $precio]))
            ->assertSessionHasErrors('precio_proveedor');
    })->with(['0', '-5', '10.555', 'abc', '9000000.01']);

    it('acepta el tope del precio del proveedor con la utilidad máxima', function () {
        $catalogo = Catalogo::factory()->create();

        $this->actingAs($catalogo->user)
            ->post('/articulos', datosArticulo($catalogo, ['precio_proveedor' => '9000000', 'utilidad_porcentaje' => '999.99']))
            ->assertSessionHasNoErrors();

        expect(Articulo::sole()->precio_unitario_sin_iva)->toBe('98999100.00');
    });

    it('rechaza una utilidad inválida', function (string $utilidad) {
        $catalogo = Catalogo::factory()->create();

        $this->actingAs($catalogo->user)
            ->post('/articulos', datosArticulo($catalogo, ['utilidad_porcentaje' => $utilidad]))
            ->assertSessionHasErrors('utilidad_porcentaje');
    })->with(['-1', '1000', '10.555', 'abc']);

    it('acepta 0% y utilidades de tres dígitos', function (string $utilidad, string $venta) {
        $catalogo = Catalogo::factory()->create();

        $this->actingAs($catalogo->user)
            ->post('/articulos', datosArticulo($catalogo, ['utilidad_porcentaje' => $utilidad]))
            ->assertSessionHasNoErrors();

        expect(Articulo::sole())
            ->utilidad_porcentaje->toBe(number_format((float) $utilidad, 2, '.', ''))
            ->precio_unitario_sin_iva->toBe($venta);
    })->with([
        '0%' => ['0', '100.00'],
        '350%' => ['350', '450.00'],
    ]);

    it('rechaza un catálogo ajeno, eliminado o de un proveedor eliminado', function () {
        $usuario = User::factory()->create();
        $ajeno = Catalogo::factory()->create();
        $eliminado = Catalogo::factory()->for(Proveedor::factory()->for($usuario))->create();
        $eliminado->delete();
        $deProveedorEliminado = Catalogo::factory()->for(Proveedor::factory()->for($usuario))->create();
        $deProveedorEliminado->proveedor->delete();

        foreach ([$ajeno, $eliminado, $deProveedorEliminado] as $catalogo) {
            $this->actingAs($usuario)
                ->post('/articulos', datosArticulo($catalogo))
                ->assertSessionHasErrors(['catalogo_id' => 'Selecciona uno de tus catálogos.']);
        }

        expect(Articulo::count())->toBe(0);
    });

    it('ignora el proveedor y los valores calculados enviados', function () {
        $catalogo = Catalogo::factory()->conDescuento(20)->conUtilidad(50)->create();
        $otroProveedor = Proveedor::factory()->for($catalogo->user)->create();

        $this->actingAs($catalogo->user)->post('/articulos', datosArticulo($catalogo, [
            'proveedor_id' => $otroProveedor->id,
            'costo_con_descuento' => '1.00',
            'precio_unitario_sin_iva' => '2.00',
            'utilidad' => '3.00',
        ]))->assertSessionHasNoErrors();

        expect(Articulo::sole())
            ->proveedor_id->toBe($catalogo->proveedor_id)
            ->costo_con_descuento->toBe('80.00')
            ->precio_unitario_sin_iva->toBe('120.69');
    });

    it('ofrece los catálogos con su descuento y su utilidad', function () {
        $catalogo = Catalogo::factory()
            ->for(Proveedor::factory()->state(['nombre_comercial' => 'Acme']))
            ->conDescuento(12.5)
            ->conUtilidad(30)
            ->create(['nombre' => 'Otoño']);
        $deProveedorEliminado = Catalogo::factory()->for(Proveedor::factory()->for($catalogo->user))->create(['nombre' => 'Viejo']);
        $deProveedorEliminado->proveedor->delete();

        $this->actingAs($catalogo->user)
            ->get('/articulos/crear')
            ->assertOk()
            ->assertSee('Acme — Otoño (12.5%)')
            ->assertDontSee('Viejo')
            ->assertSee('data-catalogos="'.e(json_encode([$catalogo->id => ['descuento' => 12.5, 'utilidad' => 30, 'utilidad_distribuidor' => 0]])).'"', false)
            ->assertSee('data-umbral="400"', false)
            ->assertSee('js/precio-articulo.js');
    });

    it('avisa cuando no hay catálogos', function () {
        $this->actingAs(User::factory()->create())
            ->get('/articulos/crear')
            ->assertOk()
            ->assertSee('primero necesitas un catálogo')
            ->assertSee(route('catalogos.create'));
    });
});

describe('nombre único por proveedor', function () {
    it('rechaza un nombre repetido en el mismo proveedor', function () {
        $articulo = Articulo::factory()->create(['nombre' => 'Sello redondo 45 mm']);

        $this->actingAs($articulo->user)
            ->post('/articulos', datosArticulo($articulo->catalogo))
            ->assertSessionHasErrors(['nombre' => 'Nombre duplicado: este proveedor ya tiene un artículo con ese nombre.']);
    });

    it('rechaza un nombre repetido en otro catálogo del mismo proveedor', function () {
        $articulo = Articulo::factory()->create(['nombre' => 'Sello redondo 45 mm']);
        $otroCatalogo = Catalogo::factory()->for($articulo->proveedor)->create();

        $this->actingAs($articulo->user)
            ->post('/articulos', datosArticulo($otroCatalogo))
            ->assertSessionHasErrors('nombre');
    });

    it('acepta el mismo nombre en otro proveedor', function () {
        $articulo = Articulo::factory()->create(['nombre' => 'Sello redondo 45 mm']);
        $otroCatalogo = Catalogo::factory()->for(Proveedor::factory()->for($articulo->user))->create();

        $this->actingAs($articulo->user)
            ->post('/articulos', datosArticulo($otroCatalogo))
            ->assertSessionHasNoErrors();
    });

    it('acepta el nombre de un artículo eliminado', function () {
        $articulo = Articulo::factory()->create(['nombre' => 'Sello redondo 45 mm']);
        $articulo->delete();

        $this->actingAs($articulo->user)
            ->post('/articulos', datosArticulo($articulo->catalogo))
            ->assertSessionHasNoErrors();
    });

    it('permite guardar un artículo sin cambiarle el nombre', function () {
        $articulo = Articulo::factory()->create(['nombre' => 'Sello redondo 45 mm']);

        $this->actingAs($articulo->user)
            ->put("/articulos/{$articulo->id}", datosArticulo($articulo->catalogo, ['modelo' => 'R-46']))
            ->assertSessionHasNoErrors();

        expect($articulo->fresh()->modelo)->toBe('R-46');
    });
});

describe('edición y eliminación', function () {
    it('muestra el formulario precargado con la descripción de las claves', function () {
        $articulo = Articulo::factory()->create(['clave_prod_serv' => '44121604', 'clave_unidad' => 'H87', 'precio_proveedor' => 100]);

        $this->actingAs($articulo->user)
            ->get("/articulos/{$articulo->id}/editar")
            ->assertOk()
            ->assertSee('Sellos de goma')
            ->assertSee('Pieza')
            ->assertSee('$116.00')
            ->assertSee('data-tasa-iva="0.16"', false)
            ->assertSee('<option value="'.$articulo->catalogo_id.'" selected>', false);
    });

    it('actualiza un artículo', function () {
        $articulo = Articulo::factory()->create(['precio_proveedor' => 100]);
        $otroCatalogo = Catalogo::factory()->for(Proveedor::factory()->for($articulo->user))->conDescuento(50)->create();

        $this->actingAs($articulo->user)
            ->put("/articulos/{$articulo->id}", datosArticulo($otroCatalogo, ['nombre' => 'Fechador', 'objeto_imp' => '01']))
            ->assertRedirect(route('articulos.index'));

        expect($articulo->fresh())
            ->nombre->toBe('Fechador')
            ->catalogo_id->toBe($otroCatalogo->id)
            ->proveedor_id->toBe($otroCatalogo->proveedor_id)
            ->costo_con_descuento->toBe('50.00')
            ->precio_unitario_sin_iva->toBe('50.00')
            ->objeto_imp->toBe(ObjetoImpuesto::NoObjeto);
    });

    it('elimina con soft delete', function () {
        $articulo = Articulo::factory()->create();

        $this->actingAs($articulo->user)
            ->delete("/articulos/{$articulo->id}")
            ->assertRedirect(route('articulos.index'))
            ->assertSessionHas('exito');

        $this->assertSoftDeleted($articulo);
    });

    it('responde 404 con un artículo ajeno', function (string $metodo, string $sufijo, array $datos) {
        $articulo = Articulo::factory()->create();

        $this->actingAs(User::factory()->create())
            ->call($metodo, "/articulos/{$articulo->id}{$sufijo}", $datos)
            ->assertNotFound();

        $this->assertNotSoftDeleted($articulo);
    })->with([
        'editar' => ['GET', '/editar', []],
        'actualizar con datos inválidos' => ['PUT', '', ['nombre' => '']],
        'eliminar' => ['DELETE', '', []],
    ]);
});

describe('precio de venta', function () {
    it('hereda la utilidad del catálogo y muestra la cadena completa', function () {
        $articulo = Articulo::factory()->for(Catalogo::factory()->conDescuento(55)->conUtilidad(99))->create(['precio_proveedor' => '347.27']);

        expect($articulo->fresh())
            ->costo_con_descuento->toBe('156.27')
            ->precio_unitario_sin_iva->toBe('311.21')
            ->utilidad->toBe(154.94)
            ->utilidad_porcentaje_efectivo->toBe('99.00');

        $this->actingAs($articulo->user)
            ->get("/articulos/{$articulo->id}/editar")
            ->assertSee('placeholder="Hereda 99% del catálogo"', false)
            ->assertSeeInOrder([
                'Precio de lista del proveedor', '$347.27',
                'Descuento del catálogo', '55%', '−$191.00',
                'Costo', '$156.27',
                'Utilidad', '99%', '+$154.71',
                'Precio de venta sin IVA', '$310.98',
                'IVA (16%)', '+$49.76',
                'Precio con IVA', '$360.74',
                'Redondeo', '+$0.26',
                'Precio final', 'con IVA', '$361.00',
            ]);
    });

    it('usa la utilidad propia sobre la del catálogo', function () {
        $articulo = Articulo::factory()->for(Catalogo::factory()->conUtilidad(10))->create(['precio_proveedor' => 100, 'utilidad_porcentaje' => 50]);

        expect($articulo->fresh())
            ->precio_unitario_sin_iva->toBe('150.00')
            ->utilidad_porcentaje_efectivo->toBe('50.00');
    });

    it('deja el precio con IVA en un peso entero, siempre hacia arriba', function (string $lista, string $utilidad, string $venta, float $conIva, float $utilidadPesos) {
        $articulo = Articulo::factory()->create(['precio_proveedor' => $lista, 'utilidad_porcentaje' => $utilidad])->fresh();

        expect($articulo)
            ->precio_unitario_sin_iva->toBe($venta)
            ->precio_unitario_con_iva->toBe($conIva)
            ->utilidad->toBe($utilidadPesos);
    })->with([
        'costo 130 al 55%: $233.74 → $234' => ['130.00', '55', '201.72', 234.0, 71.72],
        'ya entero: no se mueve' => ['180.00', '25', '225.00', 261.0, 45.0],
        '$7 es inalcanzable: $6.96 → $8' => ['6.00', '0', '6.90', 8.0, 0.9],
        'techo del markup y luego el peso' => ['100.01', '33', '133.62', 155.0, 33.61],
    ]);

    it('redondea el precio a secas cuando no es objeto de impuesto', function (ObjetoImpuesto $objeto) {
        $articulo = Articulo::factory()->create(['precio_proveedor' => '130.00', 'utilidad_porcentaje' => 55, 'objeto_imp' => $objeto])->fresh();

        expect($articulo)
            ->precio_unitario_sin_iva->toBe('202.00')
            ->precio_unitario_con_iva->toBe(202.0);
    })->with([ObjetoImpuesto::NoObjeto, ObjetoImpuesto::SiObjetoNoObligadoDesglose, ObjetoImpuesto::SiObjetoNoCausa]);

    it('recalcula al cambiar el objeto de impuesto', function () {
        $articulo = Articulo::factory()->create(['precio_proveedor' => '130.00', 'utilidad_porcentaje' => 55]);

        $articulo->update(['objeto_imp' => ObjetoImpuesto::NoObjeto]);
        expect($articulo->fresh()->precio_unitario_sin_iva)->toBe('202.00');

        $articulo->update(['objeto_imp' => ObjetoImpuesto::SiObjeto]);
        expect($articulo->fresh()->precio_unitario_sin_iva)->toBe('201.72');
    });

    it('deja en $0.00 un artículo sin costo', function () {
        $articulo = Articulo::factory()->for(Catalogo::factory()->conDescuento(100))->create(['utilidad_porcentaje' => 40])->fresh();

        expect($articulo)->precio_unitario_sin_iva->toBe('0.00')->precio_unitario_con_iva->toBe(0.0);
    });

    it('muestra el renglón de redondeo solo cuando hubo ajuste', function () {
        $catalogo = Catalogo::factory()->create();
        $conAjuste = Articulo::factory()->for($catalogo)->create(['precio_proveedor' => '130.00', 'utilidad_porcentaje' => 55]);
        $sinAjuste = Articulo::factory()->for($catalogo)->create(['precio_proveedor' => '180.00', 'utilidad_porcentaje' => 25]);

        $this->actingAs($catalogo->user)
            ->get("/articulos/{$conAjuste->id}/editar")
            ->assertSee('data-objeto="objeto_imp"', false)
            ->assertSee('<div class="" data-renglon="redondeo" >', false)
            ->assertSeeInOrder(['Precio de venta sin IVA', '$201.50', 'IVA (16%)', '+$32.24', 'Precio con IVA', '$233.74', 'Redondeo', '+$0.26', 'Precio final', 'con IVA', '$234.00']);

        $this->get("/articulos/{$sinAjuste->id}/editar")
            ->assertSee('data-renglon="redondeo"  hidden', false)
            ->assertSeeInOrder(['Precio con IVA', '$261.00', 'Precio final', '$261.00']);
    });

    it('oculta los renglones de IVA si no es objeto de impuesto', function () {
        $articulo = Articulo::factory()->create(['precio_proveedor' => '130.00', 'utilidad_porcentaje' => 55, 'objeto_imp' => ObjetoImpuesto::NoObjeto]);

        $this->actingAs($articulo->user)
            ->get("/articulos/{$articulo->id}/editar")
            ->assertSee('data-renglon="iva"  hidden', false)
            ->assertSee('data-renglon="venta-con-iva"  hidden', false)
            ->assertSee('<span data-sufijo-iva  hidden > con IVA</span>', false)
            ->assertSeeInOrder(['Precio de venta sin IVA', '$201.50', 'Redondeo', '+$0.50', 'Precio final', '$202.00']);

        $this->put("/articulos/{$articulo->id}", datosArticulo($articulo->catalogo, ['nombre' => $articulo->nombre, 'objeto_imp' => '01', 'precio_proveedor' => '130.00', 'utilidad_porcentaje' => '55']))
            ->assertSessionHas('exito', 'Artículo actualizado. Precio de venta: $202.00. Precio distribuidor: $130.00.');
    });

    it('recalcula al editar el precio de lista o la utilidad', function () {
        $articulo = Articulo::factory()->for(Catalogo::factory()->conDescuento(10))->create(['precio_proveedor' => 100]);

        $articulo->update(['precio_proveedor' => 200]);
        expect($articulo->fresh()->precio_unitario_sin_iva)->toBe('180.17');

        $articulo->update(['utilidad_porcentaje' => 25]);
        expect($articulo->fresh()->precio_unitario_sin_iva)->toBe('225.00');

        $articulo->update(['utilidad_porcentaje' => null]);
        expect($articulo->fresh()->precio_unitario_sin_iva)->toBe('180.17');
    });

    it('conserva la utilidad propia al mover el artículo de catálogo', function () {
        $articulo = Articulo::factory()->for(Catalogo::factory()->conUtilidad(10))->create(['precio_proveedor' => 100, 'utilidad_porcentaje' => 50]);
        $destino = Catalogo::factory()->for($articulo->proveedor)->conDescuento(20)->conUtilidad(5)->create();

        $this->actingAs($articulo->user)
            ->put("/articulos/{$articulo->id}", datosArticulo($destino, ['nombre' => $articulo->nombre, 'utilidad_porcentaje' => '50']))
            ->assertSessionHas('exito', 'Artículo actualizado. Precio de venta con IVA: $140.00. Precio distribuidor con IVA: $93.00.');

        expect($articulo->fresh())
            ->utilidad_porcentaje->toBe('50.00')
            ->costo_con_descuento->toBe('80.00')
            ->precio_unitario_sin_iva->toBe('120.69');
    });

    it('avisa de una utilidad alta sin impedir guardar', function () {
        $catalogo = Catalogo::factory()->create();

        $this->actingAs($catalogo->user)
            ->post('/articulos', datosArticulo($catalogo, ['utilidad_porcentaje' => '450']))
            ->assertSessionHasNoErrors();

        $this->get('/articulos/crear')
            ->assertSee('<p id="utilidad_porcentaje-aviso" class="aviso-utilidad" hidden>', false);

        // Sin JavaScript, tras un error de validación lo pinta el servidor.
        $this->from('/articulos/crear')
            ->followingRedirects()
            ->post('/articulos', datosArticulo($catalogo, ['nombre' => '', 'utilidad_porcentaje' => '450']))
            ->assertSee('<p id="utilidad_porcentaje-aviso" class="aviso-utilidad">', false)
            ->assertSee('<span data-factor>5.5</span>', false);
    });
});

describe('precio con IVA', function () {
    it('es siempre un peso entero', function (string $lista, float $conIva) {
        $articulo = Articulo::factory()->create(['precio_proveedor' => $lista]);

        expect($articulo->fresh()->precio_unitario_con_iva)->toBe($conIva);
    })->with([
        ['100.00', 116.0],
        ['99.99', 116.0],
        ['12.34', 15.0],
        ['0.01', 1.0],
    ]);
});

describe('listado', function () {
    beforeEach(function () {
        $this->usuario = User::factory()->create();
        $this->acme = Proveedor::factory()->for($this->usuario)->create(['nombre_comercial' => 'Acme']);
        $this->zeta = Proveedor::factory()->for($this->usuario)->create(['nombre_comercial' => 'Zeta Sellos']);
        $this->catalogoAcme = Catalogo::factory()->for($this->acme)->create(['nombre' => 'General']);
        $this->catalogoZeta = Catalogo::factory()->for($this->zeta)->create(['nombre' => 'Alfa']);

        Articulo::factory()->for($this->catalogoZeta)->create(['nombre' => 'Almohadilla', 'modelo' => 'ZZ-1', 'precio_proveedor' => 300]);
        Articulo::factory()->for($this->catalogoAcme)->create(['nombre' => 'Sello fechador', 'modelo' => 'AB-9', 'precio_proveedor' => 50]);
        Articulo::factory()->for($this->catalogoAcme)->create(['nombre' => 'Sello redondo', 'modelo' => 'AB-1', 'precio_proveedor' => 1000]);
    });

    it('muestra solo los artículos del usuario', function (string $ruta) {
        Articulo::factory()->create(['nombre' => 'Artículo ajeno']);

        $this->actingAs($this->usuario)
            ->get($ruta, cabecerasAjax())
            ->assertOk()
            ->assertSee('Sello fechador')
            ->assertDontSee('Artículo ajeno');
    })->with(['/articulos', '/articulos/buscar']);

    it('muestra costo y precio con IVA, sin proveedor ni catálogo', function () {
        $this->catalogoZeta->update(['descuento' => 10, 'utilidad_porcentaje' => 50]);

        $this->actingAs($this->usuario)
            ->get('/articulos/buscar', cabecerasAjax())
            ->assertDontSee('Zeta Sellos')
            ->assertDontSee('Alfa')
            ->assertSeeInOrder(['Costo', 'Precio con IVA'])
            ->assertSeeInOrder(['$270.00', '$470.00'])
            ->assertSee('$1,160.00')
            ->assertSee('data-confirmar="¿Eliminar este artículo?"', false);
    });

    it('ordena por id (orden de alta o de importación) de forma predeterminada', function () {
        Articulo::factory()->for($this->catalogoAcme)->create(['nombre' => 'Abanico']);

        $this->actingAs($this->usuario)
            ->get('/articulos')
            ->assertSeeInOrder(['Almohadilla', 'Sello fechador', 'Sello redondo', 'Abanico']);
    });

    it('ordena por cada columna en ambas direcciones', function (string $orden, array $ascendente) {
        $this->actingAs($this->usuario)
            ->get("/articulos/buscar?orden={$orden}", cabecerasAjax())
            ->assertSeeInOrder($ascendente);

        $this->actingAs($this->usuario)
            ->get("/articulos/buscar?orden={$orden}&direccion=desc", cabecerasAjax())
            ->assertSeeInOrder(array_reverse($ascendente));
    })->with([
        'modelo' => ['modelo', ['Sello redondo', 'Sello fechador', 'Almohadilla']],
        'proveedor' => ['proveedor', ['Sello', 'Almohadilla']],
        'catalogo' => ['catalogo', ['Almohadilla', 'Sello fechador']],
        'costo' => ['costo', ['Sello fechador', 'Almohadilla', 'Sello redondo']],
        'precio' => ['precio', ['Sello fechador', 'Almohadilla', 'Sello redondo']],
    ]);

    it('ordena por costo y por precio de forma independiente', function () {
        // Almohadilla: costo 150 (50% de descuento) pero precio 600 (300% de utilidad).
        $this->catalogoZeta->update(['descuento' => 50, 'utilidad_porcentaje' => 300]);
        Articulo::factory()->for($this->catalogoAcme)->create(['nombre' => 'Tinta', 'precio_proveedor' => 200]);

        $this->actingAs($this->usuario)
            ->get('/articulos/buscar?orden=costo', cabecerasAjax())
            ->assertSeeInOrder(['Almohadilla', 'Tinta']);

        $this->actingAs($this->usuario)
            ->get('/articulos/buscar?orden=precio', cabecerasAjax())
            ->assertSeeInOrder(['Tinta', 'Almohadilla']);
    });

    it('ignora un orden o una dirección desconocidos', function () {
        $this->actingAs($this->usuario)
            ->get('/articulos?orden=user_id&direccion=hacia-arriba')
            ->assertOk()
            ->assertSeeInOrder(['Almohadilla', 'Sello fechador', 'Sello redondo']);
    });

    it('marca la columna ordenada y arma el enlace que invierte la dirección', function () {
        $this->actingAs($this->usuario)
            ->get('/articulos?orden=precio&nombre=sello')
            ->assertSee('aria-sort="ascending"', false)
            ->assertSee('bi-arrow-up', false)
            ->assertSee(e(route('articulos.index', ['nombre' => 'sello', 'orden' => 'precio', 'direccion' => 'desc'])), false);
    });

    it('filtra por nombre, por modelo y por ambos', function (array $filtros, array $visibles, array $ocultos) {
        $respuesta = $this->actingAs($this->usuario)
            ->get('/articulos/buscar?'.http_build_query($filtros), cabecerasAjax());

        foreach ($visibles as $nombre) {
            $respuesta->assertSee($nombre);
        }

        foreach ($ocultos as $nombre) {
            $respuesta->assertDontSee($nombre);
        }
    })->with([
        'nombre sin distinguir mayúsculas' => [['nombre' => 'SELLO'], ['Sello fechador', 'Sello redondo'], ['Almohadilla']],
        'modelo' => [['modelo' => 'zz'], ['Almohadilla'], ['Sello fechador', 'Sello redondo']],
        'ambos' => [['nombre' => 'sello', 'modelo' => 'ab-1'], ['Sello redondo'], ['Sello fechador', 'Almohadilla']],
    ]);

    it('avisa cuando ningún artículo coincide', function () {
        $this->actingAs($this->usuario)
            ->get('/articulos/buscar?nombre=inexistente', cabecerasAjax())
            ->assertSee('Ningún artículo coincide con la búsqueda.');
    });

    it('pagina con las filas elegidas y conserva filtros y orden', function () {
        Articulo::factory()->count(27)->for($this->catalogoAcme)->create();

        $this->actingAs($this->usuario)
            ->get('/articulos?por_pagina=10&orden=modelo')
            ->assertSee('Página 1 de 3')
            ->assertSee('<option value="10" selected>', false)
            ->assertDontSee('Selecciona una opción')
            ->assertSee(e('/articulos?por_pagina=10&orden=modelo&page=2'), false);

        // Un valor fuera de la lista cae a 25 por página.
        $this->actingAs($this->usuario)
            ->get('/articulos?por_pagina=7')
            ->assertSee('Página 1 de 2');
    });

    it('devuelve el fragmento de la búsqueda dinámica', function () {
        $this->actingAs($this->usuario)
            ->get('/articulos/buscar?nombre=sello&orden=precio&direccion=desc&page=1', cabecerasAjax())
            ->assertDontSee('<html', false)
            ->assertDontSee('tabla-filtros', false)
            ->assertSee('id="articulos-titulos"', false)
            ->assertSee('id="articulos-filas"', false)
            ->assertSee('id="articulos-paginacion"', false)
            ->assertSee('<input type="hidden" name="orden" value="precio">', false)
            ->assertSee('<input type="hidden" name="direccion" value="desc">', false)
            ->assertSee(e(route('articulos.exportar', ['nombre' => 'sello', 'orden' => 'precio', 'direccion' => 'desc'])), false);
    });

    it('muestra el nombre largo completo, sin truncarlo', function () {
        $largo = str_repeat('Sello automático de fechador ', 3);
        Articulo::factory()->for($this->catalogoAcme)->create(['nombre' => $largo]);

        $this->actingAs($this->usuario)
            ->get('/articulos')
            ->assertSee('data-ficha', false)
            ->assertSee('>'.e($largo).'</a>', false)
            ->assertDontSee('class="celda-truncada enlace-ficha"', false);
    });

    it('filtra por catálogo y ofrece solo los catálogos del usuario', function () {
        $ajeno = Catalogo::factory()->create(['nombre' => 'Catálogo ajeno']);

        $this->actingAs($this->usuario)
            ->get('/articulos')
            ->assertSee('Todos los catálogos')
            ->assertSee('value="'.$this->catalogoZeta->id.'"', false)
            ->assertDontSee('Catálogo ajeno');

        $this->actingAs($this->usuario)
            ->get("/articulos/buscar?catalogo_id={$this->catalogoZeta->id}", cabecerasAjax())
            ->assertSee('Almohadilla')
            ->assertDontSee('Sello redondo');
    });
});

describe('imagen', function () {
    beforeEach(function () {
        Storage::fake('local');
    });

    it('crea un artículo con imagen', function () {
        $catalogo = Catalogo::factory()->create();

        $this->actingAs($catalogo->user)
            ->post('/articulos', datosArticulo($catalogo, ['imagen' => UploadedFile::fake()->image('cualquiera.jpg', 300, 200)]))
            ->assertRedirect(route('articulos.index'))
            ->assertSessionHas('exito', fn (string $mensaje) => str_ends_with($mensaje, 'Imagen actualizada.'));

        Storage::disk('local')->assertExists(Articulo::sole()->imagen_ruta);
    });

    it('rechaza una imagen dañada o demasiado pesada sin crear el artículo', function (Closure $imagen, string $mensaje) {
        $catalogo = Catalogo::factory()->create();

        $this->actingAs($catalogo->user)
            ->post('/articulos', datosArticulo($catalogo, ['imagen' => $imagen()]))
            ->assertSessionHasErrors(['imagen' => $mensaje]);

        expect(Articulo::count())->toBe(0);
    })->with([
        'dañada' => [fn () => UploadedFile::fake()->createWithContent('foto.jpg', substr(UploadedFile::fake()->image('foto.jpg', 400, 400)->getContent(), 0, 200)), 'La imagen no es una imagen JPG, PNG ni WEBP legible.'],
        'de más de 10 MB' => [fn () => UploadedFile::fake()->image('foto.jpg')->size(10241), 'La imagen pesa más de 10 MB. Vuelve a elegir una más ligera.'],
    ]);

    it('reemplaza la imagen al editar y borra la anterior', function () {
        $articulo = Articulo::factory()->conImagen()->create();
        $anterior = $articulo->imagen_ruta;

        $this->actingAs($articulo->user)
            ->put("/articulos/{$articulo->id}", datosArticulo($articulo->catalogo, ['imagen' => UploadedFile::fake()->image('otra.png')]))
            ->assertRedirect(route('articulos.index'));

        expect($articulo->fresh()->imagen_ruta)->not->toBeNull()->not->toBe($anterior);
        Storage::disk('local')->assertMissing($anterior);
    });

    it('quita la imagen y borra el archivo', function () {
        $articulo = Articulo::factory()->conImagen()->create();
        $anterior = $articulo->imagen_ruta;

        $this->actingAs($articulo->user)
            ->put("/articulos/{$articulo->id}", datosArticulo($articulo->catalogo, ['quitar_imagen' => '1']))
            ->assertSessionHas('exito', fn (string $mensaje) => str_ends_with($mensaje, 'Imagen quitada.'));

        expect($articulo->fresh()->imagen_ruta)->toBeNull();
        Storage::disk('local')->assertMissing($anterior);
    });

    it('no deja asignar la ruta de la imagen desde el formulario', function () {
        $articulo = Articulo::factory()->create();

        $this->actingAs($articulo->user)
            ->put("/articulos/{$articulo->id}", datosArticulo($articulo->catalogo, ['imagen_ruta' => '../../.env']));

        expect($articulo->fresh()->imagen_ruta)->toBeNull();
    });

    it('muestra la imagen actual en la edición con la URL versionada', function () {
        $articulo = Articulo::factory()->conImagen()->create();

        $this->actingAs($articulo->user)
            ->get("/articulos/{$articulo->id}/editar")
            ->assertSee(route('articulos.imagen', [$articulo, 'v' => $articulo->imagen_version]), false)
            ->assertSee('Quitar imagen');
    });

    it('lleva a la ficha solo nombre, modelo, precio con IVA e imagen', function () {
        $articulo = Articulo::factory()->conImagen()->create(['modelo' => 'R-45', 'precio_proveedor' => 100]);

        $this->actingAs($articulo->user)
            ->get('/articulos')
            ->assertSee('data-modelo="R-45"', false)
            ->assertSee('data-precio="$116.00"', false)
            ->assertSee('data-etiqueta-precio="Precio con IVA"', false)
            ->assertSee('data-imagen="'.e(route('articulos.imagen', [$articulo, 'v' => $articulo->imagen_version])).'"', false)
            ->assertSee('id="ficha-articulo"', false);
    });

    it('no rotula la ficha como con IVA si no es objeto de impuesto', function () {
        $articulo = Articulo::factory()->create(['precio_proveedor' => 100, 'objeto_imp' => ObjetoImpuesto::NoObjeto]);

        $this->actingAs($articulo->user)
            ->get('/articulos')
            ->assertSee('data-precio="$100.00"', false)
            ->assertSee('data-etiqueta-precio="Precio"', false);
    });
});
