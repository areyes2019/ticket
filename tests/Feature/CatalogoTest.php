<?php

use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

describe('acceso', function () {
    it('pide iniciar sesión', function (string $ruta) {
        $this->get($ruta)->assertRedirect(route('login'));
    })->with(['/catalogos', '/catalogos/crear']);

    it('saca a un usuario suspendido', function () {
        $this->actingAs(User::factory()->suspendido()->create())
            ->get('/catalogos')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    });

    it('muestra el enlace de catálogos en el menú', function () {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertSee(route('catalogos.index'))
            ->assertSee('bi-collection', false);
    });
});

describe('listado', function () {
    it('muestra solo los catálogos del usuario, con proveedor, descuento y artículos', function () {
        $catalogo = Catalogo::factory()
            ->for(Proveedor::factory()->state(['nombre_comercial' => 'Papelería Propia']))
            ->conDescuento(15)
            ->create(['nombre' => 'Otoño']);
        Articulo::factory()->count(2)->for($catalogo)->create();
        Catalogo::factory()->create(['nombre' => 'Catálogo ajeno']);

        $this->actingAs($catalogo->user)
            ->get('/catalogos')
            ->assertOk()
            ->assertSeeInOrder(['Otoño', 'Papelería Propia', '15%', '2'])
            ->assertDontSee('Catálogo ajeno')
            ->assertSee('data-confirmar="¿Eliminar este catálogo?"', false);
    });

    it('busca por nombre del catálogo o del proveedor', function () {
        $usuario = User::factory()->create();
        $norte = Proveedor::factory()->for($usuario)->create(['nombre_comercial' => 'Aceros del Norte']);
        $sur = Proveedor::factory()->for($usuario)->create(['nombre_comercial' => 'Distribuidora Sur']);
        Catalogo::factory()->for($norte)->create(['nombre' => 'General']);
        Catalogo::factory()->for($sur)->create(['nombre' => 'Norteño']);
        Catalogo::factory()->for($sur)->create(['nombre' => 'Oficina']);

        $this->actingAs($usuario)
            ->get('/catalogos?buscar=norte')
            ->assertSee('General')
            ->assertSee('Norteño')
            ->assertDontSee('Oficina');
    });

    it('avisa cuando ningún catálogo coincide', function () {
        $this->actingAs(User::factory()->create())
            ->get('/catalogos?buscar=nada')
            ->assertSee('Ningún catálogo coincide con la búsqueda.')
            ->assertSee('Limpiar');
    });

    it('sigue mostrando el nombre de un proveedor eliminado', function () {
        $catalogo = Catalogo::factory()->for(Proveedor::factory()->state(['nombre_comercial' => 'Zeta Sellos']))->create();
        $catalogo->proveedor->delete();

        $this->actingAs($catalogo->user)
            ->get('/catalogos')
            ->assertSee('Zeta Sellos');
    });
});

describe('alta', function () {
    beforeEach(function () {
        $this->proveedor = Proveedor::factory()->create();
        $this->usuario = $this->proveedor->user;
    });

    it('crea un catálogo', function () {
        $this->actingAs($this->usuario)
            ->post('/catalogos', ['proveedor_id' => $this->proveedor->id, 'nombre' => 'Otoño', 'descuento' => '12.5'])
            ->assertRedirect(route('catalogos.index'))
            ->assertSessionHas('exito');

        expect(Catalogo::sole())
            ->user_id->toBe($this->usuario->id)
            ->proveedor_id->toBe($this->proveedor->id)
            ->nombre->toBe('Otoño')
            ->descuento->toBe('12.50');
    });

    it('toma un descuento vacío como 0%', function (array $descuento) {
        $this->actingAs($this->usuario)
            ->post('/catalogos', ['proveedor_id' => $this->proveedor->id, 'nombre' => 'General', ...$descuento])
            ->assertSessionHasNoErrors();

        expect(Catalogo::sole()->descuento)->toBe('0.00');
    })->with([
        'vacío' => [['descuento' => '']],
        'ausente' => [[]],
    ]);

    it('exige proveedor y nombre', function (string $campo) {
        $this->actingAs($this->usuario)
            ->post('/catalogos', [...['proveedor_id' => $this->proveedor->id, 'nombre' => 'General'], $campo => ''])
            ->assertSessionHasErrors($campo);

        expect(Catalogo::count())->toBe(0);
    })->with(['proveedor_id', 'nombre']);

    it('rechaza un descuento inválido', function (string $descuento) {
        $this->actingAs($this->usuario)
            ->post('/catalogos', ['proveedor_id' => $this->proveedor->id, 'nombre' => 'General', 'descuento' => $descuento])
            ->assertSessionHasErrors('descuento');
    })->with(['-1', '100.01', '10.555', 'abc']);

    it('acepta los extremos 0 y 100', function (string $descuento) {
        $this->actingAs($this->usuario)
            ->post('/catalogos', ['proveedor_id' => $this->proveedor->id, 'nombre' => 'General', 'descuento' => $descuento])
            ->assertSessionHasNoErrors();
    })->with(['0', '100']);

    it('rechaza un proveedor ajeno o eliminado', function () {
        $ajeno = Proveedor::factory()->create();
        $eliminado = Proveedor::factory()->for($this->usuario)->create();
        $eliminado->delete();

        foreach ([$ajeno, $eliminado] as $proveedor) {
            $this->actingAs($this->usuario)
                ->post('/catalogos', ['proveedor_id' => $proveedor->id, 'nombre' => 'General'])
                ->assertSessionHasErrors(['proveedor_id' => 'Selecciona uno de tus proveedores.']);
        }

        expect(Catalogo::count())->toBe(0);
    });

    it('avisa cuando no hay proveedores', function () {
        $this->actingAs(User::factory()->create())
            ->get('/catalogos/crear')
            ->assertOk()
            ->assertSee('primero necesitas un proveedor')
            ->assertSee(route('proveedores.create'));
    });
});

describe('nombre único por proveedor', function () {
    it('rechaza un nombre repetido en el mismo proveedor', function () {
        $catalogo = Catalogo::factory()->create(['nombre' => 'General']);

        $this->actingAs($catalogo->user)
            ->post('/catalogos', ['proveedor_id' => $catalogo->proveedor_id, 'nombre' => 'General'])
            ->assertSessionHasErrors(['nombre' => 'Nombre duplicado: este proveedor ya tiene un catálogo con ese nombre.']);
    });

    it('acepta el mismo nombre en otro proveedor o tras eliminar el catálogo', function () {
        $catalogo = Catalogo::factory()->create(['nombre' => 'General']);
        $otroProveedor = Proveedor::factory()->for($catalogo->user)->create();

        $this->actingAs($catalogo->user)
            ->post('/catalogos', ['proveedor_id' => $otroProveedor->id, 'nombre' => 'General'])
            ->assertSessionHasNoErrors();

        $catalogo->delete();

        $this->post('/catalogos', ['proveedor_id' => $catalogo->proveedor_id, 'nombre' => 'General'])
            ->assertSessionHasNoErrors();
    });

    it('rechaza renombrar a un nombre ya usado en el mismo proveedor', function () {
        $general = Catalogo::factory()->create(['nombre' => 'General']);
        $otro = Catalogo::factory()->for($general->proveedor)->create(['nombre' => 'Otoño']);

        $this->actingAs($general->user)
            ->put("/catalogos/{$otro->id}", ['nombre' => 'General', 'descuento' => '0'])
            ->assertSessionHasErrors('nombre');
    });
});

describe('edición y eliminación', function () {
    it('muestra el formulario con el proveedor de solo lectura', function () {
        $catalogo = Catalogo::factory()->for(Proveedor::factory()->state(['nombre_comercial' => 'Acme']))->create();

        $this->actingAs($catalogo->user)
            ->get("/catalogos/{$catalogo->id}/editar")
            ->assertOk()
            ->assertSee('value="Acme"', false)
            ->assertSee('El proveedor no se puede cambiar.')
            ->assertDontSee('name="proveedor_id"', false);
    });

    it('actualiza nombre y descuento sin cambiar el proveedor', function () {
        $catalogo = Catalogo::factory()->create();
        $otroProveedor = Proveedor::factory()->for($catalogo->user)->create();

        $this->actingAs($catalogo->user)
            ->put("/catalogos/{$catalogo->id}", ['nombre' => 'Primavera', 'descuento' => '20', 'proveedor_id' => $otroProveedor->id])
            ->assertRedirect(route('catalogos.index'))
            ->assertSessionHas('exito');

        expect($catalogo->fresh())
            ->nombre->toBe('Primavera')
            ->descuento->toBe('20.00')
            ->proveedor_id->toBe($catalogo->proveedor_id);
    });

    it('elimina con soft delete un catálogo sin artículos', function () {
        $catalogo = Catalogo::factory()->create();
        Articulo::factory()->for($catalogo)->create()->delete();

        $this->actingAs($catalogo->user)
            ->delete("/catalogos/{$catalogo->id}")
            ->assertRedirect(route('catalogos.index'))
            ->assertSessionHas('exito');

        $this->assertSoftDeleted($catalogo);
    });

    it('no elimina un catálogo con artículos', function () {
        $catalogo = Catalogo::factory()->create();
        Articulo::factory()->for($catalogo)->create();

        $this->actingAs($catalogo->user)
            ->followingRedirects()
            ->delete("/catalogos/{$catalogo->id}")
            ->assertSee('No se puede eliminar: el catálogo tiene artículos asociados');

        $this->assertNotSoftDeleted($catalogo);
    });

    it('responde 404 con un catálogo ajeno', function (string $metodo, string $sufijo, array $datos) {
        $catalogo = Catalogo::factory()->create();

        $this->actingAs(User::factory()->create())
            ->call($metodo, "/catalogos/{$catalogo->id}{$sufijo}", $datos)
            ->assertNotFound();

        $this->assertNotSoftDeleted($catalogo);
    })->with([
        'editar' => ['GET', '/editar', []],
        'actualizar con datos inválidos' => ['PUT', '', ['nombre' => '']],
        'eliminar' => ['DELETE', '', []],
    ]);
});

describe('utilidad', function () {
    beforeEach(function () {
        $this->proveedor = Proveedor::factory()->create();
        $this->usuario = $this->proveedor->user;
    });

    it('guarda la utilidad y la toma como 0% si se deja vacía', function (array $utilidad, string $esperada) {
        $this->actingAs($this->usuario)
            ->post('/catalogos', ['proveedor_id' => $this->proveedor->id, 'nombre' => 'General', ...$utilidad])
            ->assertSessionHasNoErrors();

        expect(Catalogo::sole()->utilidad_porcentaje)->toBe($esperada);
    })->with([
        'capturada' => [['utilidad_porcentaje' => '122.5'], '122.50'],
        'vacía' => [['utilidad_porcentaje' => ''], '0.00'],
        'ausente' => [[], '0.00'],
    ]);

    it('rechaza una utilidad inválida', function (string $utilidad) {
        $this->actingAs($this->usuario)
            ->post('/catalogos', ['proveedor_id' => $this->proveedor->id, 'nombre' => 'General', 'utilidad_porcentaje' => $utilidad])
            ->assertSessionHasErrors('utilidad_porcentaje');
    })->with(['-1', '1000', '10.555', 'abc']);

    it('muestra la utilidad en el listado y el aviso de utilidad alta en el formulario', function () {
        $catalogo = Catalogo::factory()->for($this->proveedor)->conDescuento(10)->conUtilidad(122.5)->create(['nombre' => 'Otoño']);

        $this->actingAs($this->usuario)
            ->get('/catalogos')
            ->assertSeeInOrder(['Descuento', 'Utilidad', 'Otoño', '10%', '122.5%']);

        $this->get("/catalogos/{$catalogo->id}/editar")
            ->assertSee('data-umbral="400"', false)
            ->assertSee('<p id="utilidad_porcentaje-aviso" class="aviso-utilidad" hidden>', false)
            ->assertSee('js/precio-articulo.js');
    });
});

describe('recálculo de precios', function () {
    beforeEach(function () {
        $this->catalogo = Catalogo::factory()->conDescuento(10)->conUtilidad(25)->create();
        $this->hereda = Articulo::factory()->for($this->catalogo)->create(['precio_proveedor' => 200]);
        $this->propia = Articulo::factory()->for($this->catalogo)->create(['precio_proveedor' => 200, 'utilidad_porcentaje' => 50]);
        $this->eliminado = Articulo::factory()->for($this->catalogo)->create(['precio_proveedor' => 200]);
        $this->eliminado->delete();
        $this->otro = Articulo::factory()->for(Catalogo::factory()->conDescuento(10)->conUtilidad(25))->create(['precio_proveedor' => 200]);
    });

    it('recalcula todos los artículos al cambiar el descuento', function () {
        $this->catalogo->update(['descuento' => 20]);

        expect($this->hereda->fresh())->costo_con_descuento->toBe('160.00')->precio_unitario_sin_iva->toBe('200.00')
            ->and($this->propia->fresh())->costo_con_descuento->toBe('160.00')->precio_unitario_sin_iva->toBe('240.52')
            ->and(Articulo::withTrashed()->find($this->eliminado->id)->precio_unitario_sin_iva)->toBe('200.00')
            ->and($this->otro->fresh()->precio_unitario_sin_iva)->toBe('225.00');
    });

    it('recalcula solo los que heredan al cambiar la utilidad', function () {
        $this->catalogo->update(['utilidad_porcentaje' => 30]);

        expect($this->hereda->fresh()->precio_unitario_sin_iva)->toBe('234.48')
            ->and($this->propia->fresh()->precio_unitario_sin_iva)->toBe('270.69')
            ->and($this->otro->fresh()->precio_unitario_sin_iva)->toBe('225.00');
    });

    it('no recalcula si no cambian descuento ni utilidad', function () {
        DB::table('articulos')->where('id', $this->hereda->id)->update(['precio_unitario_sin_iva' => 1]);

        $this->catalogo->update(['nombre' => 'Renombrado', 'descuento' => '10.00']);

        expect($this->hereda->fresh()->precio_unitario_sin_iva)->toBe('1.00');
    });

    it('cuenta exactamente los artículos cuyo precio cambiaría', function (string $descuento, string $utilidad, int $esperados) {
        expect($this->catalogo->articulosAfectados($descuento, $utilidad))->toBe($esperados);
    })->with([
        'descuento: todos, sin eliminados' => ['20', '25', 2],
        'utilidad: solo los que heredan' => ['10', '30', 1],
        'ambos' => ['20', '30', 2],
        'sin cambio' => ['10.00', '25', 0],
    ]);

    it('no cuenta un cambio que no mueve ningún centavo', function () {
        // 100 × 1.2 = 120.00 y 100 × 1.205 = 120.50 aterrizan en el mismo
        // peso con IVA ($140.00 → 120.69); 100 × 1.21 = 121.00 sube a $141.00.
        $catalogo = Catalogo::factory()->conUtilidad(20)->create();
        Articulo::factory()->for($catalogo)->create(['precio_proveedor' => '100.00']);

        expect($catalogo->articulosAfectados('0', '20.5'))->toBe(0)
            ->and($catalogo->articulosAfectados('0', '21'))->toBe(1);
    });

    it('pide confirmación antes de recalcular y no guarda nada', function () {
        $this->actingAs($this->catalogo->user)
            ->put("/catalogos/{$this->catalogo->id}", ['nombre' => 'Nuevo nombre', 'descuento' => '10', 'utilidad_porcentaje' => '30'])
            ->assertRedirect(route('catalogos.edit', $this->catalogo))
            ->assertSessionHas('confirmar_recalculo', 1);

        expect($this->catalogo->fresh())->nombre->not->toBe('Nuevo nombre')->utilidad_porcentaje->toBe('25.00')
            ->and($this->hereda->fresh()->precio_unitario_sin_iva)->toBe('225.00');

        $this->get("/catalogos/{$this->catalogo->id}/editar")
            ->assertSee('Se recalculará el precio de venta de')
            ->assertSee('<strong>1</strong>', false)
            ->assertSee('value="Nuevo nombre"', false)
            ->assertSee('value="30"', false)
            ->assertSee('name="confirmar" value="1"', false)
            ->assertSee('Confirmar y guardar')
            ->assertSee('bi-check-lg', false)
            ->assertDontSee('>Guardar</button>', false);
    });

    it('guarda y recalcula al confirmar', function () {
        $this->actingAs($this->catalogo->user)
            ->put("/catalogos/{$this->catalogo->id}", ['nombre' => $this->catalogo->nombre, 'descuento' => '20', 'utilidad_porcentaje' => '25', 'confirmar' => '1'])
            ->assertRedirect(route('catalogos.index'))
            ->assertSessionHas('exito', 'Catálogo actualizado. Se recalculó el precio de 2 artículos.');

        expect($this->catalogo->fresh()->descuento)->toBe('20.00')
            ->and($this->hereda->fresh()->precio_unitario_sin_iva)->toBe('200.00');
    });

    it('guarda directo si ningún precio cambia', function () {
        $this->actingAs($this->catalogo->user)
            ->put("/catalogos/{$this->catalogo->id}", ['nombre' => 'Renombrado', 'descuento' => '10', 'utilidad_porcentaje' => '25'])
            ->assertRedirect(route('catalogos.index'))
            ->assertSessionHas('exito', 'Catálogo actualizado.');

        expect($this->catalogo->fresh()->nombre)->toBe('Renombrado');
    });
});

describe('migraciones de datos', function () {
    it('pasa los artículos existentes a un catálogo "General" de su proveedor', function () {
        $precios = require database_path('migrations/2026_09_26_120000_add_precio_proveedor_y_utilidad.php');
        $migracion = require database_path('migrations/2026_09_26_100001_add_catalogo_to_articulos_table.php');
        $precios->down();
        $migracion->down();

        $usuario = User::factory()->create();
        $conArticulos = Proveedor::factory()->for($usuario)->create();
        $eliminado = Proveedor::factory()->for($usuario)->create();
        $sinArticulos = Proveedor::factory()->for($usuario)->create();
        $eliminado->delete();

        $fila = fn (Proveedor $proveedor, string $nombre, ?string $borrado = null) => [
            'user_id' => $usuario->id, 'proveedor_id' => $proveedor->id, 'nombre' => $nombre, 'modelo' => 'M-1',
            'clave_prod_serv' => '44121600', 'clave_unidad' => 'H87', 'objeto_imp' => '02',
            'precio_unitario_sin_iva' => 123.45, 'deleted_at' => $borrado,
        ];
        DB::table('articulos')->insert([
            $fila($conArticulos, 'Sello'),
            $fila($conArticulos, 'Borrado', now()),
            $fila($eliminado, 'Huérfano'),
        ]);

        $migracion->up();

        expect(Schema::hasColumns('articulos', ['catalogo_id', 'precio_con_descuento']))->toBeTrue()
            ->and(Catalogo::withTrashed()->where('proveedor_id', $sinArticulos->id)->exists())->toBeFalse();

        foreach ([$conArticulos, $eliminado] as $proveedor) {
            $general = DB::table('catalogos')->where('proveedor_id', $proveedor->id)->sole();

            expect($general)
                ->nombre->toBe('General')
                ->user_id->toBe($usuario->id)
                ->and((float) $general->descuento)->toBe(0.0);
        }

        expect(DB::table('articulos')->whereNull('catalogo_id')->count())->toBe(0)
            ->and(DB::table('articulos')->count())->toBe(3)
            ->and(DB::table('articulos')->pluck('precio_con_descuento')->map(fn ($precio) => (float) $precio)->unique()->all())->toBe([123.45]);

        $precios->up();
    });

    it('toma el precio actual como precio de lista y recalcula la cadena', function () {
        $migracion = require database_path('migrations/2026_09_26_120000_add_precio_proveedor_y_utilidad.php');
        $catalogo = Catalogo::factory()->conDescuento(55)->create();
        $vivo = Articulo::factory()->for($catalogo)->create(['precio_proveedor' => '347.27']);
        $borrado = Articulo::factory()->for($catalogo)->create(['precio_proveedor' => '100.00', 'utilidad_porcentaje' => 20]);
        $borrado->delete();

        $migracion->down();

        expect(Schema::hasColumns('articulos', ['precio_proveedor', 'utilidad_porcentaje', 'costo_con_descuento']))->toBeFalse()
            ->and(Schema::hasColumn('catalogos', 'utilidad_porcentaje'))->toBeFalse()
            ->and((float) DB::table('articulos')->where('id', $vivo->id)->value('precio_unitario_sin_iva'))->toBe(347.27)
            ->and((float) DB::table('articulos')->where('id', $vivo->id)->value('precio_con_descuento'))->toBe(156.27);

        $migracion->up();

        expect(Catalogo::find($catalogo->id)->utilidad_porcentaje)->toBe('0.00')
            ->and(Articulo::find($vivo->id))
            ->precio_proveedor->toBe('347.27')
            ->utilidad_porcentaje->toBeNull()
            ->costo_con_descuento->toBe('156.27')
            ->precio_unitario_sin_iva->toBe('156.27')
            ->and(Articulo::withTrashed()->find($borrado->id))
            ->precio_proveedor->toBe('100.00')
            ->utilidad_porcentaje->toBeNull()
            ->precio_unitario_sin_iva->toBe('45.00');
    });
});
