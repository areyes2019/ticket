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

describe('precio con descuento', function () {
    it('lo calcula al crear el artículo, redondeado a centavos', function (string $descuento, string $precio, string $esperado) {
        $catalogo = Catalogo::factory()->create(['descuento' => $descuento]);

        $articulo = Articulo::factory()->for($catalogo)->create(['precio_unitario_sin_iva' => $precio]);

        expect($articulo->fresh()->precio_con_descuento)->toBe($esperado);
    })->with([
        'sin descuento' => ['0', '100.00', '100.00'],
        '15%' => ['15', '100.00', '85.00'],
        'empate hacia arriba' => ['10', '10.05', '9.05'],
        'decimales en el descuento' => ['12.5', '99.99', '87.49'],
        '100%' => ['100', '50.00', '0.00'],
    ]);

    it('lo recalcula al cambiar el precio del artículo', function () {
        $articulo = Articulo::factory()->for(Catalogo::factory()->conDescuento(10))->create(['precio_unitario_sin_iva' => 100]);

        $articulo->update(['precio_unitario_sin_iva' => 200]);

        expect($articulo->fresh()->precio_con_descuento)->toBe('180.00');
    });

    it('lo recalcula en bloque al cambiar el descuento del catálogo', function () {
        $catalogo = Catalogo::factory()->create();
        $articulos = collect(['100.00', '10.05', '99.99', '0.01'])
            ->map(fn (string $precio) => Articulo::factory()->for($catalogo)->create(['precio_unitario_sin_iva' => $precio]));
        $articulos->last()->delete();
        $otro = Articulo::factory()->create(['precio_unitario_sin_iva' => 100]);

        $this->actingAs($catalogo->user)
            ->put("/catalogos/{$catalogo->id}", ['nombre' => $catalogo->nombre, 'descuento' => '10']);

        // El recálculo en bloque (SQL) debe dar lo mismo que el cálculo por fila (PHP).
        $catalogo->refresh();

        foreach ($articulos as $articulo) {
            $guardado = Articulo::withTrashed()->find($articulo->id);

            expect($guardado->precio_con_descuento)
                ->toBe(number_format($catalogo->precioConDescuento($guardado->precio_unitario_sin_iva), 2, '.', ''));
        }

        expect(Articulo::find($articulos[1]->id)->precio_con_descuento)->toBe('9.05')
            ->and($otro->fresh()->precio_con_descuento)->toBe('100.00');
    });

    it('no recalcula si el descuento no cambia', function () {
        $catalogo = Catalogo::factory()->conDescuento(10)->create();
        $articulo = Articulo::factory()->for($catalogo)->create(['precio_unitario_sin_iva' => 100]);
        DB::table('articulos')->where('id', $articulo->id)->update(['precio_con_descuento' => 1]);

        $catalogo->update(['nombre' => 'Renombrado']);

        expect($articulo->fresh()->precio_con_descuento)->toBe('1.00');
    });
});

describe('migración de datos', function () {
    it('pasa los artículos existentes a un catálogo "General" de su proveedor', function () {
        $migracion = require database_path('migrations/2026_09_26_100001_add_catalogo_to_articulos_table.php');
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
            $general = Catalogo::where('proveedor_id', $proveedor->id)->sole();

            expect($general)
                ->nombre->toBe('General')
                ->descuento->toBe('0.00')
                ->user_id->toBe($usuario->id);
        }

        expect(DB::table('articulos')->whereNull('catalogo_id')->count())->toBe(0)
            ->and(DB::table('articulos')->count())->toBe(3)
            ->and(Articulo::withTrashed()->pluck('precio_con_descuento')->unique()->all())->toBe(['123.45']);
    });
});
