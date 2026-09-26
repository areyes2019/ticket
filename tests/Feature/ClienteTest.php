<?php

use App\Enums\RegimenFiscal;
use App\Models\Cliente;
use App\Models\User;

/**
 * @param  array<string, string>  $cambios
 * @return array<string, string>
 */
function datosCliente(array $cambios = []): array
{
    return [
        'rfc' => 'ADE010101AB1',
        'razon_social' => 'ACEROS DEL NORTE',
        'regimen_fiscal' => '601',
        'codigo_postal_fiscal' => '20000',
        ...$cambios,
    ];
}

describe('acceso', function () {
    it('pide iniciar sesión para ver los clientes', function () {
        $this->get('/clientes')->assertRedirect(route('login'));
    });

    it('responde 401 a la búsqueda dinámica sin sesión', function () {
        $this->get('/clientes/buscar', cabecerasAjax())->assertUnauthorized();
    });

    it('saca a un usuario suspendido', function () {
        $this->actingAs(User::factory()->suspendido()->create())
            ->get('/clientes')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    });

    it('muestra el enlace de clientes en el menú', function () {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertSee(route('clientes.index'))
            ->assertSee('bi-people', false);
    });
});

describe('listado', function () {
    it('muestra solo los clientes del usuario', function (string $ruta) {
        $usuario = User::factory()->create();
        Cliente::factory()->for($usuario)->create(['razon_social' => 'PAPELERIA PROPIA']);
        Cliente::factory()->create(['razon_social' => 'FERRETERIA AJENA']);

        $this->actingAs($usuario)
            ->get($ruta, cabecerasAjax())
            ->assertOk()
            ->assertSee('PAPELERIA PROPIA')
            ->assertDontSee('FERRETERIA AJENA');
    })->with(['/clientes', '/clientes/buscar']);

    it('ordena por razón social', function () {
        $usuario = User::factory()->create();
        Cliente::factory()->for($usuario)->create(['razon_social' => 'ZAPATERIA']);
        Cliente::factory()->for($usuario)->create(['razon_social' => 'ABARROTES']);

        $this->actingAs($usuario)
            ->get('/clientes')
            ->assertSeeInOrder(['ABARROTES', 'ZAPATERIA']);
    });

    it('pide confirmación en el botón de eliminar', function () {
        $cliente = Cliente::factory()->create();

        $this->actingAs($cliente->user)
            ->get('/clientes')
            ->assertSee('data-confirmar="¿Eliminar este cliente?"', false);
    });
});

describe('búsqueda', function () {
    beforeEach(function () {
        $this->usuario = User::factory()->create();

        Cliente::factory()->for($this->usuario)->create([
            'razon_social' => 'ACEROS DEL NORTE',
            'nombre_comercial' => 'Aceros Norte',
            'nombre_contacto' => 'Luis Pérez',
            'rfc' => 'ADE010101AB1',
        ]);
        Cliente::factory()->for($this->usuario)->create([
            'razon_social' => 'DISTRIBUIDORA DEL SUR',
            'nombre_comercial' => 'Dissur',
            'nombre_contacto' => 'Marta Norte',
            'rfc' => 'DSU020202CD2',
        ]);
    });

    it('filtra por cada columna', function (string $filtro, string $termino) {
        $this->actingAs($this->usuario)
            ->get('/clientes/buscar?'.http_build_query([$filtro => $termino]), cabecerasAjax())
            ->assertSee('ACEROS DEL NORTE')
            ->assertDontSee('DISTRIBUIDORA DEL SUR');
    })->with([
        'razón social' => ['razon_social', 'aceros'],
        'nombre comercial' => ['nombre_comercial', 'aceros n'],
        'nombre de contacto' => ['nombre_contacto', 'luis'],
        'RFC en minúsculas y con espacios' => ['rfc', ' ade 0101 '],
    ]);

    it('combina los filtros', function () {
        $this->actingAs($this->usuario)
            ->get('/clientes/buscar?nombre_contacto=norte&rfc=DSU', cabecerasAjax())
            ->assertSee('DISTRIBUIDORA DEL SUR')
            ->assertDontSee('ACEROS DEL NORTE');

        $this->get('/clientes/buscar?nombre_contacto=luis&rfc=DSU', cabecerasAjax())
            ->assertSee('Ningún cliente coincide con la búsqueda.');
    });

    it('funciona sin JavaScript en la página completa con los filtros precargados', function () {
        $this->actingAs($this->usuario)
            ->get('/clientes?razon_social=aceros')
            ->assertSee('ACEROS DEL NORTE')
            ->assertDontSee('DISTRIBUIDORA DEL SUR')
            ->assertSee('value="aceros"', false);
    });

    it('devuelve solo filas y paginación, sin el encabezado de filtros', function () {
        $this->actingAs($this->usuario)
            ->get('/clientes/buscar', cabecerasAjax())
            ->assertSee('id="clientes-filas"', false)
            ->assertSee('id="clientes-paginacion"', false)
            ->assertDontSee('<thead', false)
            ->assertDontSee('<html', false);
    });

    it('apunta la paginación al listado completo conservando los filtros', function () {
        Cliente::factory()->for($this->usuario)->count(26)->create(['nombre_contacto' => 'Contacto Norte']);

        $this->actingAs($this->usuario)
            ->get('/clientes/buscar?nombre_contacto=norte', cabecerasAjax())
            ->assertViewHas('clientes', fn ($clientes) => $clientes->count() === 25
                && str_starts_with($clientes->nextPageUrl(), route('clientes.index').'?')
                && str_contains($clientes->nextPageUrl(), 'nombre_contacto=norte'));
    });
});

describe('alta', function () {
    it('crea un cliente con RFC y teléfono normalizados', function () {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->post('/clientes', datosCliente([
                'rfc' => ' ade 010101 ab1 ',
                'nombre_comercial' => 'Aceros Norte',
                'nombre_contacto' => 'Luis Pérez',
                'correo' => 'ventas@aceros.test',
                'telefono' => '(449) 123-4567',
                'direccion_comercial' => 'Av. Siempre Viva 123',
            ]))
            ->assertRedirect(route('clientes.index'))
            ->assertSessionHas('exito');

        expect($usuario->clientes()->sole())
            ->rfc->toBe('ADE010101AB1')
            ->regimen_fiscal->toBe(RegimenFiscal::GeneralPersonasMorales)
            ->telefono->toBe('+524491234567')
            ->nombre_contacto->toBe('Luis Pérez');
    });

    it('crea un cliente solo con los datos fiscales', function () {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->post('/clientes', datosCliente())
            ->assertSessionHasNoErrors();

        expect($usuario->clientes()->sole())
            ->nombre_comercial->toBeNull()
            ->telefono->toBeNull();
    });

    it('acepta los RFC genéricos', function (string $rfc) {
        $this->actingAs(User::factory()->create())
            ->post('/clientes', datosCliente(['rfc' => $rfc]))
            ->assertSessionHasNoErrors();
    })->with(['XAXX010101000', 'XEXX010101000']);

    it('rechaza datos inválidos', function (array $datos, string $campo, string $mensaje) {
        $this->actingAs(User::factory()->create())
            ->post('/clientes', datosCliente($datos))
            ->assertSessionHasErrors([$campo => $mensaje]);

        expect(Cliente::count())->toBe(0);
    })->with([
        'sin RFC' => [['rfc' => ''], 'rfc', 'El campo RFC es obligatorio.'],
        'RFC inválido' => [['rfc' => 'ABC123'], 'rfc', 'El RFC no tiene un formato válido.'],
        'sin razón social' => [['razon_social' => ''], 'razon_social', 'El campo razón social es obligatorio.'],
        'régimen fuera del catálogo' => [['regimen_fiscal' => '999'], 'regimen_fiscal', 'Selecciona un régimen fiscal del catálogo del SAT.'],
        'sin código postal' => [['codigo_postal_fiscal' => ''], 'codigo_postal_fiscal', 'El campo código postal fiscal es obligatorio.'],
        'código postal de 4 dígitos' => [['codigo_postal_fiscal' => '2000'], 'codigo_postal_fiscal', 'El código postal fiscal debe tener 5 dígitos.'],
        'código postal con letras' => [['codigo_postal_fiscal' => '2000A'], 'codigo_postal_fiscal', 'El código postal fiscal debe tener 5 dígitos.'],
        'correo inválido' => [['correo' => 'no-es-correo'], 'correo', 'El campo correo debe ser un correo electrónico válido.'],
        'teléfono corto' => [['telefono' => '449123'], 'telefono', 'El teléfono debe tener 10 dígitos.'],
    ]);

    it('ignora el user_id enviado en el formulario', function () {
        $usuario = User::factory()->create();
        $otro = User::factory()->create();

        $this->actingAs($usuario)->post('/clientes', datosCliente(['user_id' => (string) $otro->id]));

        expect($usuario->clientes()->count())->toBe(1)
            ->and($otro->clientes()->count())->toBe(0);
    });
});

describe('RFC único por usuario', function () {
    it('rechaza un RFC que el usuario ya registró', function () {
        $usuario = User::factory()->create();
        Cliente::factory()->for($usuario)->create(['rfc' => 'ADE010101AB1']);

        $this->actingAs($usuario)
            ->post('/clientes', datosCliente(['rfc' => 'ade010101ab1']))
            ->assertSessionHasErrors(['rfc' => 'RFC duplicado: ya tienes un cliente registrado con ese RFC.']);
    });

    it('acepta el mismo RFC para otro usuario', function () {
        Cliente::factory()->create(['rfc' => 'ADE010101AB1']);

        $this->actingAs(User::factory()->create())
            ->post('/clientes', datosCliente())
            ->assertSessionHasNoErrors();
    });

    it('acepta el RFC de un cliente eliminado', function () {
        $usuario = User::factory()->create();
        Cliente::factory()->for($usuario)->create(['rfc' => 'ADE010101AB1'])->delete();

        $this->actingAs($usuario)
            ->post('/clientes', datosCliente())
            ->assertSessionHasNoErrors();
    });
});

describe('tipo de persona', function () {
    it('se infiere del RFC', function (string $rfc, ?string $tipo) {
        expect(Cliente::factory()->make(['rfc' => $rfc])->tipo_persona)->toBe($tipo);
    })->with([
        'moral' => ['ADE010101AB1', 'moral'],
        'física' => ['PEGJ800101AB1', 'fisica'],
        'público en general' => ['XAXX010101000', null],
        'extranjero' => ['XEXX010101000', null],
    ]);
});

describe('edición', function () {
    it('muestra el formulario precargado', function () {
        $cliente = Cliente::factory()->create([
            'razon_social' => 'ACEROS DEL NORTE',
            'regimen_fiscal' => RegimenFiscal::SimplificadoConfianza,
        ]);

        $this->actingAs($cliente->user)
            ->get(route('clientes.edit', $cliente))
            ->assertOk()
            ->assertSee('value="ACEROS DEL NORTE"', false)
            ->assertSee('<option value="626" selected>', false);
    });

    it('actualiza el cliente conservando su propio RFC', function () {
        $cliente = Cliente::factory()->create(['rfc' => 'ADE010101AB1']);

        $this->actingAs($cliente->user)
            ->put(route('clientes.update', $cliente), datosCliente(['razon_social' => 'NUEVA RAZON']))
            ->assertRedirect(route('clientes.index'))
            ->assertSessionHasNoErrors();

        expect($cliente->fresh()->razon_social)->toBe('NUEVA RAZON');
    });

    it('no daña un teléfono ya normalizado al editar sin tocarlo', function () {
        $cliente = Cliente::factory()->create(['rfc' => 'ADE010101AB1', 'telefono' => '+524491234567']);

        $this->actingAs($cliente->user)
            ->put(route('clientes.update', $cliente), datosCliente(['telefono' => '+524491234567']))
            ->assertSessionHasNoErrors();

        expect($cliente->fresh()->telefono)->toBe('+524491234567');
    });
});

describe('eliminación', function () {
    it('elimina el cliente con soft delete', function () {
        $cliente = Cliente::factory()->create();

        $this->actingAs($cliente->user)
            ->delete(route('clientes.destroy', $cliente))
            ->assertRedirect(route('clientes.index'))
            ->assertSessionHas('exito');

        $this->assertSoftDeleted($cliente);
    });
});

describe('clientes de otro usuario', function () {
    it('responde 404', function (string $metodo, string $ruta) {
        $ajeno = Cliente::factory()->create();

        $this->actingAs(User::factory()->create())
            ->{$metodo}(route($ruta, $ajeno), datosCliente(['razon_social' => 'INTENTO']))
            ->assertNotFound();

        expect($ajeno->fresh())
            ->razon_social->not->toBe('INTENTO')
            ->deleted_at->toBeNull();
    })->with([
        'editar' => ['get', 'clientes.edit'],
        'actualizar' => ['put', 'clientes.update'],
        'eliminar' => ['delete', 'clientes.destroy'],
    ]);

    it('responde 404 aunque los datos sean inválidos', function () {
        $ajeno = Cliente::factory()->create();

        $this->actingAs(User::factory()->create())
            ->put(route('clientes.update', $ajeno), ['rfc' => ''])
            ->assertNotFound();
    });

    it('responde 404 también al administrador', function () {
        $ajeno = Cliente::factory()->create();

        $this->actingAs(User::factory()->administrador()->create())
            ->get(route('clientes.edit', $ajeno))
            ->assertNotFound();
    });
});
