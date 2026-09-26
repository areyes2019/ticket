<?php

use App\Models\Proveedor;
use App\Models\User;

describe('acceso', function () {
    it('pide iniciar sesión para ver los proveedores', function () {
        $this->get('/proveedores')->assertRedirect(route('login'));
    });

    it('saca a un usuario suspendido', function () {
        $this->actingAs(User::factory()->suspendido()->create())
            ->get('/proveedores')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    });

    it('muestra el enlace de proveedores en el menú', function () {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertSee(route('proveedores.index'))
            ->assertSee('bi-truck', false);
    });
});

describe('listado', function () {
    it('muestra solo los proveedores del usuario', function () {
        $usuario = User::factory()->create();
        Proveedor::factory()->for($usuario)->create(['nombre_comercial' => 'Papelería Propia']);
        Proveedor::factory()->create(['nombre_comercial' => 'Ferretería Ajena']);

        $this->actingAs($usuario)
            ->get('/proveedores')
            ->assertOk()
            ->assertSee('Papelería Propia')
            ->assertDontSee('Ferretería Ajena');
    });

    it('busca por nombre comercial o nombre de contacto', function () {
        $usuario = User::factory()->create();
        Proveedor::factory()->for($usuario)->create(['nombre_comercial' => 'Aceros del Norte', 'nombre_contacto' => 'Luis']);
        Proveedor::factory()->for($usuario)->create(['nombre_comercial' => 'Distribuidora Sur', 'nombre_contacto' => 'Marta Norte']);
        Proveedor::factory()->for($usuario)->create(['nombre_comercial' => 'Papelera Centro', 'nombre_contacto' => 'Ana']);

        $this->actingAs($usuario)
            ->get('/proveedores?buscar=norte')
            ->assertSee('Aceros del Norte')
            ->assertSee('Distribuidora Sur')
            ->assertDontSee('Papelera Centro');
    });

    it('ofrece limpiar la búsqueda solo cuando hay una activa', function () {
        $this->actingAs(User::factory()->create());

        $this->get('/proveedores?buscar=norte')->assertSee('Limpiar');
        $this->get('/proveedores')->assertDontSee('Limpiar');
    });

    it('conserva la búsqueda al cambiar de página', function () {
        $usuario = User::factory()->create();
        Proveedor::factory()->for($usuario)->count(26)->create(['nombre_contacto' => 'Contacto Norte']);

        $this->actingAs($usuario)
            ->get('/proveedores?buscar=norte')
            ->assertViewHas('proveedores', fn ($proveedores) => $proveedores->count() === 25
                && str_contains($proveedores->nextPageUrl(), 'buscar=norte'));
    });
});

describe('alta', function () {
    it('crea un proveedor con el teléfono y el RFC normalizados', function () {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->post('/proveedores', [
                'nombre_comercial' => 'Aceros del Norte',
                'nombre_contacto' => 'Luis Pérez',
                'correo' => 'ventas@aceros.test',
                'telefono' => '(449) 123-4567',
                'rfc' => ' ade 010101 ab1 ',
            ])
            ->assertRedirect(route('proveedores.index'))
            ->assertSessionHas('exito');

        $proveedor = $usuario->proveedores()->sole();

        expect($proveedor)
            ->nombre_comercial->toBe('Aceros del Norte')
            ->telefono->toBe('+524491234567')
            ->rfc->toBe('ADE010101AB1')
            ->tiene_ordenes_activas->toBeFalse();
    });

    it('crea un proveedor solo con el nombre comercial', function () {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->post('/proveedores', ['nombre_comercial' => 'Aceros del Norte'])
            ->assertRedirect(route('proveedores.index'));

        expect($usuario->proveedores()->sole())
            ->correo->toBeNull()
            ->telefono->toBeNull()
            ->rfc->toBeNull();
    });

    it('rechaza datos inválidos', function (array $datos, string $campo, string $mensaje) {
        $this->actingAs(User::factory()->create())
            ->post('/proveedores', ['nombre_comercial' => 'Aceros del Norte', ...$datos])
            ->assertSessionHasErrors([$campo => $mensaje]);

        expect(Proveedor::count())->toBe(0);
    })->with([
        'sin nombre comercial' => [['nombre_comercial' => ''], 'nombre_comercial', 'El campo nombre comercial es obligatorio.'],
        'correo inválido' => [['correo' => 'no-es-correo'], 'correo', 'El campo correo debe ser un correo electrónico válido.'],
        'teléfono corto' => [['telefono' => '449123'], 'telefono', 'El teléfono debe tener 10 dígitos.'],
        'teléfono largo' => [['telefono' => '44912345678'], 'telefono', 'El teléfono debe tener 10 dígitos.'],
        'RFC inválido' => [['rfc' => 'ABC123'], 'rfc', 'El RFC no tiene un formato válido.'],
    ]);

    it('ignora tiene_ordenes_activas enviado en el formulario', function () {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->post('/proveedores', [
            'nombre_comercial' => 'Aceros del Norte',
            'tiene_ordenes_activas' => '1',
        ]);

        expect($usuario->proveedores()->sole()->tiene_ordenes_activas)->toBeFalse();
    });
});

describe('RFC único por usuario', function () {
    it('rechaza un RFC que el usuario ya registró', function () {
        $usuario = User::factory()->create();
        Proveedor::factory()->for($usuario)->create(['rfc' => 'ADE010101AB1']);

        $this->actingAs($usuario)
            ->post('/proveedores', ['nombre_comercial' => 'Otro', 'rfc' => 'ade010101ab1'])
            ->assertSessionHasErrors(['rfc' => 'RFC duplicado: ya tienes un proveedor registrado con ese RFC.']);
    });

    it('acepta el mismo RFC para otro usuario', function () {
        Proveedor::factory()->create(['rfc' => 'ADE010101AB1']);

        $this->actingAs(User::factory()->create())
            ->post('/proveedores', ['nombre_comercial' => 'Otro', 'rfc' => 'ADE010101AB1'])
            ->assertSessionHasNoErrors();
    });

    it('acepta el RFC de un proveedor eliminado', function () {
        $usuario = User::factory()->create();
        Proveedor::factory()->for($usuario)->create(['rfc' => 'ADE010101AB1'])->delete();

        $this->actingAs($usuario)
            ->post('/proveedores', ['nombre_comercial' => 'Otro', 'rfc' => 'ADE010101AB1'])
            ->assertSessionHasNoErrors();
    });

    it('permite varios proveedores sin RFC', function () {
        $usuario = User::factory()->create();
        Proveedor::factory()->for($usuario)->create(['rfc' => null]);

        $this->actingAs($usuario)
            ->post('/proveedores', ['nombre_comercial' => 'Otro'])
            ->assertSessionHasNoErrors();
    });
});

describe('edición', function () {
    it('muestra el formulario precargado', function () {
        $proveedor = Proveedor::factory()->create(['nombre_comercial' => 'Aceros del Norte']);

        $this->actingAs($proveedor->user)
            ->get(route('proveedores.edit', $proveedor))
            ->assertOk()
            ->assertSee('value="Aceros del Norte"', false);
    });

    it('actualiza el proveedor conservando su propio RFC', function () {
        $proveedor = Proveedor::factory()->create(['rfc' => 'ADE010101AB1']);

        $this->actingAs($proveedor->user)
            ->put(route('proveedores.update', $proveedor), [
                'nombre_comercial' => 'Nuevo nombre',
                'rfc' => 'ADE010101AB1',
            ])
            ->assertRedirect(route('proveedores.index'))
            ->assertSessionHasNoErrors();

        expect($proveedor->fresh()->nombre_comercial)->toBe('Nuevo nombre');
    });

    it('no daña un teléfono ya normalizado al editar sin tocarlo', function () {
        $proveedor = Proveedor::factory()->create(['telefono' => '+524491234567']);

        $this->actingAs($proveedor->user)
            ->put(route('proveedores.update', $proveedor), [
                'nombre_comercial' => $proveedor->nombre_comercial,
                'telefono' => '+524491234567',
            ])
            ->assertSessionHasNoErrors();

        expect($proveedor->fresh()->telefono)->toBe('+524491234567');
    });
});

describe('eliminación', function () {
    it('elimina el proveedor con soft delete', function () {
        $proveedor = Proveedor::factory()->create();

        $this->actingAs($proveedor->user)
            ->delete(route('proveedores.destroy', $proveedor))
            ->assertRedirect(route('proveedores.index'))
            ->assertSessionHas('exito');

        $this->assertSoftDeleted($proveedor);
    });

    it('no elimina un proveedor con órdenes de compra activas', function () {
        $proveedor = Proveedor::factory()->conOrdenesActivas()->create();

        $this->actingAs($proveedor->user)
            ->delete(route('proveedores.destroy', $proveedor))
            ->assertRedirect(route('proveedores.index'))
            ->assertSessionHas('error', 'No se puede eliminar: tiene órdenes de compra activas');

        $this->assertNotSoftDeleted($proveedor);
    });

    it('pide confirmación en el botón de eliminar', function () {
        $proveedor = Proveedor::factory()->create();

        $this->actingAs($proveedor->user)
            ->get('/proveedores')
            ->assertSee('data-confirmar="¿Eliminar este proveedor?"', false);
    });
});

describe('proveedores de otro usuario', function () {
    it('responde 404', function (string $metodo, string $ruta) {
        $ajeno = Proveedor::factory()->create();

        $this->actingAs(User::factory()->create())
            ->{$metodo}(route($ruta, $ajeno), ['nombre_comercial' => 'Intento'])
            ->assertNotFound();

        expect($ajeno->fresh())
            ->nombre_comercial->not->toBe('Intento')
            ->deleted_at->toBeNull();
    })->with([
        'editar' => ['get', 'proveedores.edit'],
        'actualizar' => ['put', 'proveedores.update'],
        'eliminar' => ['delete', 'proveedores.destroy'],
    ]);

    it('responde 404 aunque los datos sean inválidos', function () {
        $ajeno = Proveedor::factory()->create();

        $this->actingAs(User::factory()->create())
            ->put(route('proveedores.update', $ajeno), ['nombre_comercial' => ''])
            ->assertNotFound();
    });

    it('responde 404 también al administrador', function () {
        $ajeno = Proveedor::factory()->create();

        $this->actingAs(User::factory()->administrador()->create())
            ->get(route('proveedores.edit', $ajeno))
            ->assertNotFound();
    });
});
