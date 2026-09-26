<?php

use App\Enums\ObjetoImpuesto;
use App\Models\Articulo;
use App\Models\Proveedor;
use App\Models\User;

beforeEach(function () {
    sembrarCatalogosSat();
});

/**
 * @param  array<string, mixed>  $cambios
 * @return array<string, mixed>
 */
function datosArticulo(Proveedor $proveedor, array $cambios = []): array
{
    return [
        'proveedor_id' => $proveedor->id,
        'nombre' => 'Sello redondo 45 mm',
        'modelo' => 'R-45',
        'clave_prod_serv' => '44121604',
        'clave_unidad' => 'H87',
        'objeto_imp' => '02',
        'precio_unitario_sin_iva' => '100.00',
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
    it('crea un artículo', function () {
        $proveedor = Proveedor::factory()->create();

        $this->actingAs($proveedor->user)
            ->post('/articulos', datosArticulo($proveedor))
            ->assertRedirect(route('articulos.index'))
            ->assertSessionHas('exito');

        $articulo = Articulo::sole();

        expect($articulo->user_id)->toBe($proveedor->user_id)
            ->and($articulo->proveedor_id)->toBe($proveedor->id)
            ->and($articulo->objeto_imp)->toBe(ObjetoImpuesto::SiObjeto)
            ->and($articulo->precio_unitario_sin_iva)->toBe('100.00');
    });

    it('guarda la clave de unidad en mayúsculas', function () {
        $proveedor = Proveedor::factory()->create();

        $this->actingAs($proveedor->user)->post('/articulos', datosArticulo($proveedor, ['clave_unidad' => ' h87 ']));

        expect(Articulo::sole()->clave_unidad)->toBe('H87');
    });

    it('no permite asignar el usuario desde el formulario', function () {
        $proveedor = Proveedor::factory()->create();
        $otro = User::factory()->create();

        $this->actingAs($proveedor->user)->post('/articulos', datosArticulo($proveedor, ['user_id' => $otro->id]));

        expect(Articulo::sole()->user_id)->toBe($proveedor->user_id);
    });

    it('exige los campos obligatorios', function (string $campo) {
        $proveedor = Proveedor::factory()->create();

        $this->actingAs($proveedor->user)
            ->post('/articulos', datosArticulo($proveedor, [$campo => '']))
            ->assertSessionHasErrors($campo);

        expect(Articulo::count())->toBe(0);
    })->with(['proveedor_id', 'nombre', 'modelo', 'clave_prod_serv', 'clave_unidad', 'objeto_imp', 'precio_unitario_sin_iva']);

    it('rechaza claves SAT que no están en el catálogo', function (string $campo, string $valor) {
        $proveedor = Proveedor::factory()->create();

        $this->actingAs($proveedor->user)
            ->post('/articulos', datosArticulo($proveedor, [$campo => $valor]))
            ->assertSessionHasErrors($campo);
    })->with([
        'producto/servicio' => ['clave_prod_serv', '99999999'],
        'unidad' => ['clave_unidad', 'XYZ'],
        'objeto de impuesto' => ['objeto_imp', '05'],
    ]);

    it('rechaza un precio inválido', function (string $precio) {
        $proveedor = Proveedor::factory()->create();

        $this->actingAs($proveedor->user)
            ->post('/articulos', datosArticulo($proveedor, ['precio_unitario_sin_iva' => $precio]))
            ->assertSessionHasErrors('precio_unitario_sin_iva');
    })->with(['0', '-5', '10.555', 'abc', '100000000']);

    it('rechaza un proveedor ajeno o eliminado', function () {
        $usuario = User::factory()->create();
        $ajeno = Proveedor::factory()->create();
        $eliminado = Proveedor::factory()->for($usuario)->create();
        $eliminado->delete();

        foreach ([$ajeno, $eliminado] as $proveedor) {
            $this->actingAs($usuario)
                ->post('/articulos', datosArticulo($proveedor))
                ->assertSessionHasErrors('proveedor_id');
        }

        expect(Articulo::count())->toBe(0);
    });

    it('avisa cuando no hay proveedores', function () {
        $this->actingAs(User::factory()->create())
            ->get('/articulos/crear')
            ->assertOk()
            ->assertSee('primero necesitas un proveedor')
            ->assertSee(route('proveedores.create'));
    });
});

describe('nombre único por proveedor', function () {
    it('rechaza un nombre repetido en el mismo proveedor', function () {
        $articulo = Articulo::factory()->create(['nombre' => 'Sello redondo 45 mm']);

        $this->actingAs($articulo->user)
            ->post('/articulos', datosArticulo($articulo->proveedor))
            ->assertSessionHasErrors(['nombre' => 'Nombre duplicado: este proveedor ya tiene un artículo con ese nombre.']);
    });

    it('acepta el mismo nombre en otro proveedor', function () {
        $articulo = Articulo::factory()->create(['nombre' => 'Sello redondo 45 mm']);
        $otroProveedor = Proveedor::factory()->for($articulo->user)->create();

        $this->actingAs($articulo->user)
            ->post('/articulos', datosArticulo($otroProveedor))
            ->assertSessionHasNoErrors();
    });

    it('acepta el nombre de un artículo eliminado', function () {
        $articulo = Articulo::factory()->create(['nombre' => 'Sello redondo 45 mm']);
        $articulo->delete();

        $this->actingAs($articulo->user)
            ->post('/articulos', datosArticulo($articulo->proveedor))
            ->assertSessionHasNoErrors();
    });

    it('permite guardar un artículo sin cambiarle el nombre', function () {
        $articulo = Articulo::factory()->create(['nombre' => 'Sello redondo 45 mm']);

        $this->actingAs($articulo->user)
            ->put("/articulos/{$articulo->id}", datosArticulo($articulo->proveedor, ['modelo' => 'R-46']))
            ->assertSessionHasNoErrors();

        expect($articulo->fresh()->modelo)->toBe('R-46');
    });
});

describe('edición y eliminación', function () {
    it('muestra el formulario precargado con la descripción de las claves', function () {
        $articulo = Articulo::factory()->create(['clave_prod_serv' => '44121604', 'clave_unidad' => 'H87', 'precio_unitario_sin_iva' => 100]);

        $this->actingAs($articulo->user)
            ->get("/articulos/{$articulo->id}/editar")
            ->assertOk()
            ->assertSee('Sellos de goma')
            ->assertSee('Pieza')
            ->assertSee('$116.00')
            ->assertSee('data-tasa-iva="0.16"', false);
    });

    it('actualiza un artículo', function () {
        $articulo = Articulo::factory()->create();
        $otroProveedor = Proveedor::factory()->for($articulo->user)->create();

        $this->actingAs($articulo->user)
            ->put("/articulos/{$articulo->id}", datosArticulo($otroProveedor, ['nombre' => 'Fechador', 'objeto_imp' => '01']))
            ->assertRedirect(route('articulos.index'));

        expect($articulo->fresh())
            ->nombre->toBe('Fechador')
            ->proveedor_id->toBe($otroProveedor->id)
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

describe('precio con IVA', function () {
    it('calcula el 16% redondeado a centavos', function (string $sinIva, float $conIva) {
        $articulo = Articulo::factory()->create(['precio_unitario_sin_iva' => $sinIva]);

        expect($articulo->fresh()->precio_unitario_con_iva)->toBe($conIva);
    })->with([
        ['100.00', 116.0],
        ['99.99', 115.99],
        ['12.34', 14.31],
        ['0.01', 0.01],
    ]);
});

describe('listado', function () {
    beforeEach(function () {
        $this->usuario = User::factory()->create();
        $this->acme = Proveedor::factory()->for($this->usuario)->create(['nombre_comercial' => 'Acme']);
        $this->zeta = Proveedor::factory()->for($this->usuario)->create(['nombre_comercial' => 'Zeta Sellos']);

        Articulo::factory()->for($this->zeta)->create(['nombre' => 'Almohadilla', 'modelo' => 'ZZ-1', 'precio_unitario_sin_iva' => 300]);
        Articulo::factory()->for($this->acme)->create(['nombre' => 'Sello fechador', 'modelo' => 'AB-9', 'precio_unitario_sin_iva' => 50]);
        Articulo::factory()->for($this->acme)->create(['nombre' => 'Sello redondo', 'modelo' => 'AB-1', 'precio_unitario_sin_iva' => 1000]);
    });

    it('muestra solo los artículos del usuario', function (string $ruta) {
        Articulo::factory()->create(['nombre' => 'Artículo ajeno']);

        $this->actingAs($this->usuario)
            ->get($ruta, cabecerasAjax())
            ->assertOk()
            ->assertSee('Sello fechador')
            ->assertDontSee('Artículo ajeno');
    })->with(['/articulos', '/articulos/buscar']);

    it('muestra proveedor y precio con IVA', function () {
        $this->actingAs($this->usuario)
            ->get('/articulos')
            ->assertSee('Zeta Sellos')
            ->assertSee('$348.00')
            ->assertSee('$1,160.00')
            ->assertSee('data-confirmar="¿Eliminar este artículo?"', false);
    });

    it('ordena por nombre de forma predeterminada', function () {
        $this->actingAs($this->usuario)
            ->get('/articulos')
            ->assertSeeInOrder(['Almohadilla', 'Sello fechador', 'Sello redondo']);
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
        'precio' => ['precio', ['Sello fechador', 'Almohadilla', 'Sello redondo']],
    ]);

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
        Articulo::factory()->count(27)->for($this->acme)->create();

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

    it('sigue mostrando el nombre de un proveedor eliminado', function () {
        $this->zeta->delete();

        $this->actingAs($this->usuario)
            ->get('/articulos')
            ->assertSee('Zeta Sellos');
    });

    it('trunca los textos largos y deja el texto completo en el título', function () {
        $largo = str_repeat('Sello automático de fechador ', 3);
        Articulo::factory()->for($this->acme)->create(['nombre' => $largo]);

        $this->actingAs($this->usuario)
            ->get('/articulos')
            ->assertSee('<span class="celda-truncada" title="'.e($largo).'">', false);
    });
});
