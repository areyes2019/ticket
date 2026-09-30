<?php

use App\Enums\EstadoFactura;
use App\Enums\ObjetoImpuesto;
use App\Enums\TipoErrorTimbrado;
use App\Models\Articulo;
use App\Models\Cliente;
use App\Models\Factura;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<string, mixed>  $cambios
 * @return array<string, mixed>
 */
function lineaFactura(Articulo $articulo, array $cambios = []): array
{
    return [
        'articulo_id' => (string) $articulo->id,
        'cantidad' => '1',
        'descripcion' => $articulo->nombre,
        'modelo' => $articulo->modelo,
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
function datosFactura(Cliente $cliente, array $lineas, array $cambios = []): array
{
    return [
        'cliente_id' => $cliente->id,
        'uso_cfdi' => 'G03',
        'metodo_pago' => 'PUE',
        'forma_pago' => '03',
        'lineas' => $lineas,
        'descuento_global_tipo' => '',
        'descuento_global_valor' => '',
        ...$cambios,
    ];
}

beforeEach(function () {
    config([
        'services.facturapi.llave' => 'sk_test_prueba',
        'services.facturapi.emisor' => ['rfc' => 'EKU9003173C9', 'razon_social' => 'ESCUELA KEMPER URGATE', 'regimen_fiscal' => '601', 'codigo_postal' => '26015'],
    ]);

    $this->user = User::factory()->create();
    $this->cliente = Cliente::factory()->for($this->user)->create(['correo' => 'cliente@ejemplo.mx']);
    $this->articulo = articuloFacturable($this->user);
});

describe('acceso', function () {
    it('pide iniciar sesión', function (string $ruta) {
        $this->get($ruta)->assertRedirect(route('login'));
    })->with(['/facturas', '/facturas/crear']);

    it('saca a un usuario suspendido', function () {
        $this->actingAs(User::factory()->suspendido()->create())
            ->get('/facturas')
            ->assertRedirect(route('login'));
    });

    it('muestra el enlace de facturas en el menú', function () {
        $this->actingAs($this->user)->get('/dashboard')->assertSee(route('facturas.index'));
    });

    it('responde 404 en cualquier acción sobre una factura ajena', function (string $metodo, string $sufijo) {
        Http::fake();
        $ajena = Factura::factory()->timbrada()->conLinea()->create();

        $this->actingAs($this->user)->call($metodo, "/facturas/{$ajena->id}{$sufijo}")->assertNotFound();

        Http::assertNothingSent();
    })->with([
        ['GET', ''],
        ['GET', '/editar'],
        ['PUT', ''],
        ['DELETE', ''],
        ['POST', '/timbrar'],
        ['POST', '/cancelar'],
        ['GET', '/xml'],
        ['GET', '/pdf'],
        ['POST', '/enviar'],
        ['POST', '/complemento-pago'],
    ]);

    it('rechaza un cliente o un artículo ajeno', function () {
        Http::fake();
        $otro = User::factory()->create();

        $this->actingAs($this->user)
            ->post('/facturas', datosFactura(Cliente::factory()->for($otro)->create(), [lineaFactura(articuloFacturable($otro))]))
            ->assertSessionHasErrors(['cliente_id', 'lineas.0.articulo_id']);

        expect(Factura::count())->toBe(0);
    });
});

describe('alta y timbrado', function () {
    it('crea, timbra y guarda sellos, folio fiscal y copias fiscales', function () {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado())]);

        $respuesta = $this->actingAs($this->user)->post('/facturas', datosFactura($this->cliente, [lineaFactura($this->articulo)]));

        $factura = Factura::sole();
        $respuesta->assertRedirect(route('facturas.show', $factura))->assertSessionHas('exito');

        expect($factura->estado)->toBe(EstadoFactura::Timbrada)
            ->and($factura->folio)->toBe(1)
            ->and($factura->facturapi_invoice_id)->toBe('64f0c0ffee0000000000abcd')
            ->and($factura->uuid_fiscal)->toBe('5E2D6AFF-2DD7-43D1-83D3-14C1ACA396D9')
            ->and($factura->folioFiscal())->toBe('A123')
            ->and($factura->sello_cfdi)->toBe('SELLO-CFDI')
            ->and($factura->sello_sat)->toBe('SELLO-SAT')
            ->and($factura->no_certificado_sat)->toBe('00001000000504465028')
            ->and($factura->fecha_timbrado->toIso8601String())->toBe('2026-09-28T12:30:00+00:00')
            ->and($factura->cadena_original_sat)->toStartWith('||1.1|')
            ->and($factura->version_comprobante)->toBe('4.0')
            ->and($factura->url_verificacion_sat)->toContain('verificacfdi')
            ->and($factura->receptor_rfc)->toBe($this->cliente->rfc)
            ->and($factura->receptor_correo)->toBe('cliente@ejemplo.mx')
            ->and($factura->emisor_rfc)->toBe('EKU9003173C9')
            ->and($factura->lugar_expedicion)->toBe('26015')
            ->and($factura->total)->toBe('116.00')
            ->and($factura->error_timbrado)->toBeNull();

        $linea = $factura->lineas->sole();
        expect($linea->clave_prod_serv)->toBe($this->articulo->clave_prod_serv)
            ->and($linea->clave_unidad)->toBe($this->articulo->clave_unidad)
            ->and($linea->objeto_imp)->toBe(ObjetoImpuesto::SiObjeto);

        Http::assertSent(fn (Request $peticion) => $peticion->method() === 'POST'
            && $peticion->hasHeader('Authorization', 'Bearer sk_test_prueba')
            && $peticion['items'][0]['product']['tax_included'] === false
            && $peticion['customer']['tax_id'] === $this->cliente->rfc);
    });

    it('toma el emisor de issuer_info cuando la respuesta lo trae', function () {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado(['issuer_info' => [
            'legal_name' => 'ABDIAS REYES REYNA', 'tax_id' => 'RERA7701272R1', 'tax_system' => '612', 'address' => ['zip' => '38024'],
        ]]))]);

        $this->actingAs($this->user)->post('/facturas', datosFactura($this->cliente, [lineaFactura($this->articulo)]));

        expect(Factura::sole()->emisor())->toBe([
            'rfc' => 'RERA7701272R1', 'razon_social' => 'ABDIAS REYES REYNA', 'regimen_fiscal' => '612', 'codigo_postal' => '38024',
        ]);
    });

    it('manda una idempotency_key fija por factura', function () {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(['message' => 'Servicio no disponible'], 503)]);

        $this->actingAs($this->user)->post('/facturas', datosFactura($this->cliente, [lineaFactura($this->articulo)]));
        $factura = Factura::sole();
        $this->actingAs($this->user)->post("/facturas/{$factura->id}/timbrar");

        $llaves = Http::recorded()->map(fn (array $par) => $par[0]['idempotency_key'])->unique()->values()->all();
        expect($llaves)->toBe([$factura->referenciaExterna()]);
        Http::assertSent(fn (Request $peticion) => $peticion['external_id'] === $factura->referenciaExterna());
    });

    it('tras un timeout adopta el CFDI que facturapi.io sí timbró (409) en vez de timbrar otro', function () {
        $factura = Factura::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);
        $factura->registrarErrorTimbrado('facturapi.io no respondió en 30 segundos.', TipoErrorTimbrado::Pac);

        Http::fake([
            'www.facturapi.io/v2/invoices?external_id=*' => Http::response(['data' => [['id' => 'ya-timbrada']], 'total_results' => 1]),
            'www.facturapi.io/v2/invoices/ya-timbrada' => Http::response(respuestaTimbrado(['id' => 'ya-timbrada'])),
            'www.facturapi.io/v2/invoices' => Http::response(['message' => 'La clave de idempotencia (idempotency_key) ya está siendo usada.'], 409),
        ]);

        $this->actingAs($this->user)->post("/facturas/{$factura->id}/timbrar")->assertSessionHas('exito');

        expect($factura->fresh())
            ->estado->toBe(EstadoFactura::Timbrada)
            ->facturapi_invoice_id->toBe('ya-timbrada')
            ->uuid_fiscal->toBe('5E2D6AFF-2DD7-43D1-83D3-14C1ACA396D9');

        Http::assertSent(fn (Request $peticion) => str_contains($peticion->url(), 'external_id='.urlencode($factura->referenciaExterna())));
    });

    it('con 409 y sin CFDI encontrado queda pendiente para reintentar', function () {
        $factura = Factura::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);
        $factura->registrarErrorTimbrado('Error', TipoErrorTimbrado::Pac);

        Http::fake([
            'www.facturapi.io/v2/invoices?external_id=*' => Http::response(['data' => [], 'total_results' => 0]),
            'www.facturapi.io/v2/invoices' => Http::response(['message' => 'La clave de idempotencia (idempotency_key) ya está siendo usada.'], 409),
        ]);

        $this->actingAs($this->user)->post("/facturas/{$factura->id}/timbrar");

        expect($factura->fresh())
            ->estado->toBe(EstadoFactura::Pendiente)
            ->tipo_error_timbrado->toBe(TipoErrorTimbrado::Pac)
            ->error_timbrado->toContain('todavía no la encuentra');
    });

    it('calcula los totales en el servidor e ignora los que llegan', function () {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado())]);

        $this->actingAs($this->user)->post('/facturas', datosFactura($this->cliente, [lineaFactura($this->articulo, ['cantidad' => '2'])], [
            'total' => '1.00',
            'folio' => '999',
            'estado' => 'cancelada',
            'uuid_fiscal' => 'falso',
        ]));

        $factura = Factura::sole();
        expect($factura->total)->toBe('232.00')
            ->and($factura->folio)->toBe(1)
            ->and($factura->uuid_fiscal)->toBe('5E2D6AFF-2DD7-43D1-83D3-14C1ACA396D9');
    });

    it('asigna folios internos consecutivos por usuario', function () {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado())]);

        $this->actingAs($this->user)->post('/facturas', datosFactura($this->cliente, [lineaFactura($this->articulo)]));
        $this->actingAs($this->user)->post('/facturas', datosFactura($this->cliente, [lineaFactura($this->articulo)]));

        $otro = User::factory()->create();
        $this->actingAs($otro)->post('/facturas', datosFactura(Cliente::factory()->for($otro)->create(), [lineaFactura(articuloFacturable($otro))]));

        expect($this->user->facturas()->pluck('folio')->all())->toBe([1, 2])
            ->and($otro->facturas()->value('folio'))->toBe(1);
    });

    it('con datos rechazados queda pendiente y lleva a corregir', function () {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(['message' => 'El RFC del receptor no está en la lista de RFC inscritos.'], 400)]);

        $respuesta = $this->actingAs($this->user)->post('/facturas', datosFactura($this->cliente, [lineaFactura($this->articulo)]));

        $factura = Factura::sole();
        $respuesta->assertRedirect(route('facturas.edit', $factura))->assertSessionHas('error');

        expect($factura->estado)->toBe(EstadoFactura::Pendiente)
            ->and($factura->tipo_error_timbrado)->toBe(TipoErrorTimbrado::Datos)
            ->and($factura->error_timbrado)->toBe('El RFC del receptor no está en la lista de RFC inscritos.')
            ->and($factura->lineas)->toHaveCount(1);

        $this->actingAs($this->user)->get(route('facturas.edit', $factura))
            ->assertOk()
            ->assertSee('El RFC del receptor no está en la lista de RFC inscritos.');
    });

    it('con falla del PAC queda pendiente y lleva al detalle para reintentar', function () {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(['message' => 'Servicio no disponible'], 503)]);

        $respuesta = $this->actingAs($this->user)->post('/facturas', datosFactura($this->cliente, [lineaFactura($this->articulo)]));

        $factura = Factura::sole();
        $respuesta->assertRedirect(route('facturas.show', $factura));

        expect($factura->estado)->toBe(EstadoFactura::Pendiente)
            ->and($factura->tipo_error_timbrado)->toBe(TipoErrorTimbrado::Pac);

        $this->actingAs($this->user)->get(route('facturas.show', $factura))
            ->assertOk()
            ->assertSee('Reintentar timbrado')
            ->assertDontSee('Corregir datos');
    });

    it('trata un timeout como falla del PAC', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds'));

        $this->actingAs($this->user)->post('/facturas', datosFactura($this->cliente, [lineaFactura($this->articulo)]));

        $factura = Factura::sole();
        expect($factura->estado)->toBe(EstadoFactura::Pendiente)
            ->and($factura->tipo_error_timbrado)->toBe(TipoErrorTimbrado::Pac)
            ->and($factura->error_timbrado)->toBe('facturapi.io no respondió en 30 segundos.');
    });

    it('sin llave configurada no llama a facturapi.io y queda pendiente', function () {
        config(['services.facturapi.llave' => null]);
        Http::fake();

        $this->actingAs($this->user)->post('/facturas', datosFactura($this->cliente, [lineaFactura($this->articulo)]));

        Http::assertNothingSent();
        expect(Factura::sole()->estado)->toBe(EstadoFactura::Pendiente);
    });

    it('el reintento exitoso timbra y limpia el error', function () {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado())]);
        $factura = Factura::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);
        $factura->registrarErrorTimbrado('Servicio no disponible', TipoErrorTimbrado::Pac);

        $this->actingAs($this->user)->post("/facturas/{$factura->id}/timbrar")
            ->assertRedirect(route('facturas.show', $factura));

        expect($factura->fresh())
            ->estado->toBe(EstadoFactura::Timbrada)
            ->error_timbrado->toBeNull()
            ->tipo_error_timbrado->toBeNull();
    });

    it('no timbra dos veces a la vez', function () {
        Http::fake();
        $factura = Factura::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);
        $factura->registrarErrorTimbrado('Servicio no disponible', TipoErrorTimbrado::Pac);

        $candado = Cache::lock('timbrar-factura-'.$factura->id, 10);
        $candado->get();

        $this->actingAs($this->user)->post("/facturas/{$factura->id}/timbrar")
            ->assertSessionHas('error', 'Esta factura ya se está timbrando. Espera unos segundos y vuelve a abrirla.');

        Http::assertNothingSent();
        $candado->release();
    });

    it('no reintenta una factura timbrada', function () {
        Http::fake();
        $factura = Factura::factory()->for($this->cliente)->timbrada()->conLinea()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->post("/facturas/{$factura->id}/timbrar")->assertSessionHas('error');

        Http::assertNothingSent();
    });
});

describe('validación', function () {
    beforeEach(fn () => Http::fake());

    it('exige artículo en cada línea', function () {
        $this->actingAs($this->user)
            ->post('/facturas', datosFactura($this->cliente, [lineaFactura($this->articulo, ['articulo_id' => ''])]))
            ->assertSessionHasErrors('lineas.0.articulo_id');
    });

    it('rechaza el mismo artículo en dos líneas', function () {
        $this->actingAs($this->user)
            ->post('/facturas', datosFactura($this->cliente, [lineaFactura($this->articulo), lineaFactura($this->articulo)]))
            ->assertSessionHasErrors('lineas.1.articulo_id');
    });

    it('solo admite exento en un artículo que no es objeto de impuesto', function () {
        $noObjeto = articuloFacturable($this->user, ['objeto_imp' => ObjetoImpuesto::NoObjeto]);

        $this->actingAs($this->user)
            ->post('/facturas', datosFactura($this->cliente, [lineaFactura($noObjeto, ['tasa_iva' => '16'])]))
            ->assertSessionHasErrors('lineas.0.tasa_iva');

        Http::assertNothingSent();
    });

    it('exige forma 99 con PPD y la prohíbe con PUE', function (string $metodo, string $forma) {
        $this->actingAs($this->user)
            ->post('/facturas', datosFactura($this->cliente, [lineaFactura($this->articulo)], ['metodo_pago' => $metodo, 'forma_pago' => $forma]))
            ->assertSessionHasErrors('forma_pago');
    })->with([['PPD', '03'], ['PUE', '99']]);

    it('rechaza usos de CFDI que no son de una factura de ingreso', function (string $uso) {
        $this->actingAs($this->user)
            ->post('/facturas', datosFactura($this->cliente, [lineaFactura($this->articulo)], ['uso_cfdi' => $uso]))
            ->assertSessionHasErrors('uso_cfdi');
    })->with(['CP01', 'CN01', 'X99']);

    it('conserva las líneas capturadas tras un error', function () {
        $this->actingAs($this->user)
            ->from('/facturas/crear')
            ->post('/facturas', datosFactura($this->cliente, [lineaFactura($this->articulo, ['descripcion' => 'Sello editado', 'precio_unitario' => '0'])]))
            ->assertRedirect('/facturas/crear');

        $this->actingAs($this->user)->get('/facturas/crear')->assertSee('Sello editado');
    });
});

describe('corrección, borrado e inmutabilidad', function () {
    it('corrige una pendiente por datos y vuelve a timbrar', function () {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado())]);
        $factura = Factura::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);
        $factura->registrarErrorTimbrado('RFC inválido', TipoErrorTimbrado::Datos);

        $this->actingAs($this->user)
            ->put("/facturas/{$factura->id}", datosFactura($this->cliente, [lineaFactura($this->articulo, ['cantidad' => '3'])]))
            ->assertRedirect(route('facturas.show', $factura));

        expect($factura->fresh())
            ->estado->toBe(EstadoFactura::Timbrada)
            ->total->toBe('348.00');
    });

    it('no deja corregir una pendiente por falla del PAC', function () {
        $factura = Factura::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);
        $factura->registrarErrorTimbrado('Servicio no disponible', TipoErrorTimbrado::Pac);

        $this->actingAs($this->user)->get("/facturas/{$factura->id}/editar")->assertForbidden();
    });

    it('no deja editar ni eliminar una timbrada o cancelada', function (EstadoFactura $estado) {
        Http::fake();
        $factura = Factura::factory()->for($this->cliente)->timbrada()->enEstado($estado)->conLinea()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->get("/facturas/{$factura->id}/editar")->assertForbidden();
        $this->actingAs($this->user)
            ->put("/facturas/{$factura->id}", datosFactura($this->cliente, [lineaFactura($this->articulo)]))
            ->assertForbidden();
        $this->actingAs($this->user)->delete("/facturas/{$factura->id}")->assertSessionHas('error');

        expect(Factura::find($factura->id))->not->toBeNull();
        Http::assertNothingSent();
    })->with([EstadoFactura::Timbrada, EstadoFactura::Cancelada]);

    it('elimina una pendiente con sus líneas', function () {
        $factura = Factura::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);
        $factura->registrarErrorTimbrado('RFC inválido', TipoErrorTimbrado::Datos);

        $this->actingAs($this->user)->delete("/facturas/{$factura->id}")->assertRedirect(route('facturas.index'));

        expect(Factura::count())->toBe(0)
            ->and($factura->lineas()->count())->toBe(0);
    });

    it('conserva los datos fiscales timbrados aunque el cliente cambie', function () {
        $factura = Factura::factory()->for($this->cliente)->timbrada()->conLinea()->create(['user_id' => $this->user->id]);
        $rfcOriginal = $this->cliente->rfc;

        $this->cliente->update(['rfc' => 'XAXX010101000', 'razon_social' => 'OTRA RAZON']);

        $this->actingAs($this->user)->get("/facturas/{$factura->id}")
            ->assertOk()
            ->assertSee($rfcOriginal)
            ->assertDontSee('OTRA RAZON');

        expect($factura->fresh()->receptor()['rfc'])->toBe($rfcOriginal);
    });
});

describe('listado', function () {
    it('muestra solo las facturas propias', function () {
        Factura::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);
        $ajena = Factura::factory()->conLinea()->create();

        $this->actingAs($this->user)->get('/facturas')
            ->assertOk()
            ->assertSee('FAC-0001')
            ->assertDontSee($ajena->cliente->razon_social);
    });

    it('filtra por folio interno, folio fiscal, UUID y estado', function (array $filtro, bool $coincide) {
        $factura = Factura::factory()->for($this->cliente)->timbrada()->conLinea()->create([
            'user_id' => $this->user->id,
            'facturapi_folio' => 57,
            'uuid_fiscal' => 'ABCDEF12-0000-0000-0000-000000000000',
        ]);

        $respuesta = $this->actingAs($this->user)->get('/facturas?'.http_build_query($filtro))->assertOk();

        $coincide ? $respuesta->assertSee($factura->folio_formateado) : $respuesta->assertDontSee($factura->folio_formateado);
    })->with([
        [['folio' => 'FAC-0001'], true],
        [['folio' => '1'], true],
        [['folio' => 'A57'], true],
        [['folio' => 'B57'], false],
        [['uuid' => 'abcdef12'], true],
        [['uuid' => '99999'], false],
        [['estado' => 'timbrada'], true],
        [['estado' => 'pendiente'], false],
    ]);

    it('devuelve el fragmento para la búsqueda dinámica', function () {
        Factura::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->get('/facturas/buscar?cliente='.urlencode($this->cliente->razon_social), cabecerasAjax())
            ->assertOk()
            ->assertSee('facturas-filas', false)
            ->assertDontSee('<html', false);
    });

    it('pinta el botón de compartir solo en facturas con documento fiscal', function () {
        Factura::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->get('/facturas')->assertDontSee('data-compartir-pdf', false);

        Factura::factory()->for($this->cliente)->timbrada()->conLinea()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->get('/facturas')
            ->assertSee('data-compartir-pdf', false)
            ->assertSee('data-precargar="al-apuntar"', false);
    });
});
