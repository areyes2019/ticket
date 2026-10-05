<?php

use App\Enums\EstadoCotizacion;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionLinea;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\Pedido;
use App\Models\User;
use App\Services\Cotizaciones\GeneradorPdfCotizacion;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Datos válidos de un cliente nuevo, para el formulario.
 *
 * @return array<string, mixed>
 */
function clienteConDescuento(array $cambios = []): array
{
    return ['rfc' => 'ADE010101AB1', 'razon_social' => 'ACEROS DEL NORTE', 'regimen_fiscal' => '601', 'codigo_postal_fiscal' => '20000', ...$cambios];
}

/**
 * Lo que manda el formulario de cotización: una línea del artículo, con el
 * descuento de línea indicado.
 *
 * @return array<string, mixed>
 */
function cotizacionConDescuento(Cliente $cliente, int $articuloId, string $cantidad, string $precio, ?string $porcentaje, array $cambios = []): array
{
    return [
        'cliente_id' => $cliente->id,
        'descuento_global_tipo' => '',
        'descuento_global_valor' => '',
        'lineas' => [[
            'articulo_id' => (string) $articuloId,
            'cantidad' => $cantidad,
            'descripcion' => 'Sello automático',
            'modelo' => 'S-1',
            'precio_unitario' => $precio,
            'descuento_tipo' => $porcentaje === null ? '' : 'porcentaje',
            'descuento_valor' => $porcentaje ?? '',
            'tasa_iva' => '16',
        ]],
        ...$cambios,
    ];
}

/**
 * La cotización de una línea guardada y enviada, lista para facturar.
 */
function cotizacionEnviadaConDescuento(User $user, Cliente $cliente, int $articuloId, string $cantidad = '3', string $precio = '333.33', ?string $porcentaje = '15', array $cambios = []): Cotizacion
{
    test()->actingAs($user)->post('/cotizaciones', cotizacionConDescuento($cliente, $articuloId, $cantidad, $precio, $porcentaje, $cambios))->assertSessionHasNoErrors();

    $cotizacion = Cotizacion::latest('id')->firstOrFail();
    $cotizacion->forceFill(['estado' => EstadoCotizacion::Enviada])->saveQuietly();

    return $cotizacion->load('lineas');
}

/**
 * Lo que manda el formulario de factura tal como lo precarga la cotización.
 *
 * @return array<string, mixed>
 */
function facturaPrecargada(Cotizacion $cotizacion): array
{
    return [
        'cotizacion_id' => $cotizacion->id,
        'cliente_id' => $cotizacion->cliente_id,
        'uso_cfdi' => 'G03',
        'metodo_pago' => 'PUE',
        'forma_pago' => '03',
        'descuento_global_tipo' => $cotizacion->descuento_global_tipo?->value ?? '',
        'descuento_global_valor' => $cotizacion->descuento_global_valor ?? '',
        'lineas' => $cotizacion->lineas->map(fn (CotizacionLinea $linea) => array_map(
            fn ($valor) => $valor === null ? '' : (string) $valor,
            $linea->datosParaFactura(),
        ))->all(),
    ];
}

beforeEach(function () {
    config([
        'services.facturapi.llave' => 'sk_test_prueba',
        'services.facturapi.emisor' => ['rfc' => 'EKU9003173C9', 'razon_social' => 'ESCUELA KEMPER URGATE', 'regimen_fiscal' => '601', 'codigo_postal' => '26015'],
    ]);

    $this->user = User::factory()->create();
    $this->articulo = articuloFacturable($this->user);
    $this->cliente = Cliente::factory()->for($this->user)->create(['razon_social' => 'FERRETERIA LOPEZ', 'descuento_permanente' => '15']);
    $this->sinDescuento = Cliente::factory()->for($this->user)->create(['razon_social' => 'PAPELERIA SOL']);
});

describe('ficha del cliente', function () {
    it('guarda el descuento entre 0 y 50%, con hasta 2 decimales', function (string $valor, string $guardado) {
        $this->actingAs($this->user)->post('/clientes', clienteConDescuento(['descuento_permanente' => $valor]))
            ->assertSessionHasNoErrors();

        expect(Cliente::where('rfc', 'ADE010101AB1')->sole()->descuento_permanente)->toBe($guardado);
    })->with([
        'cero' => ['0', '0.00'],
        'tope' => ['50', '50.00'],
        'con decimales' => ['12.5', '12.50'],
        'en blanco' => ['', '0.00'],
    ]);

    it('sin el campo queda en 0', function () {
        $this->actingAs($this->user)->post('/clientes', clienteConDescuento())->assertSessionHasNoErrors();

        expect(Cliente::where('rfc', 'ADE010101AB1')->sole()->descuento_permanente)->toBe('0.00');
    });

    it('rechaza más de 50%, negativos y más de 2 decimales', function (string $valor, string $mensaje) {
        $this->actingAs($this->user)->post('/clientes', clienteConDescuento(['descuento_permanente' => $valor]))
            ->assertSessionHasErrors(['descuento_permanente' => $mensaje]);

        expect(Cliente::where('rfc', 'ADE010101AB1')->exists())->toBeFalse();
    })->with([
        '50.01' => ['50.01', 'El descuento permanente no puede pasar de 50%.'],
        '51' => ['51', 'El descuento permanente no puede pasar de 50%.'],
        'negativo' => ['-1', 'El descuento permanente no puede ser negativo.'],
        'tres decimales' => ['10.125', 'El descuento permanente admite hasta 2 decimales.'],
    ]);

    it('se edita y otro usuario no puede cambiarlo', function () {
        $this->actingAs($this->user)->put("/clientes/{$this->cliente->id}", clienteConDescuento([
            'rfc' => $this->cliente->rfc, 'descuento_permanente' => '20',
        ]))->assertSessionHasNoErrors();

        expect($this->cliente->fresh()->descuento_permanente)->toBe('20.00');

        $this->actingAs(User::factory()->create())->put("/clientes/{$this->cliente->id}", clienteConDescuento([
            'rfc' => $this->cliente->rfc, 'descuento_permanente' => '0',
        ]))->assertNotFound();

        expect($this->cliente->fresh()->descuento_permanente)->toBe('20.00');
    });

    it('el listado muestra el descuento, con guion si es 0', function () {
        $this->actingAs($this->user)->get('/clientes')
            ->assertOk()
            ->assertSee('Descuento')
            ->assertSee('<td class="numero">15%</td>', false)
            ->assertSee('<td class="numero">—</td>', false);
    });

    it('el formulario muestra el campo con su ayuda', function () {
        $this->actingAs($this->user)->get("/clientes/{$this->cliente->id}/editar")
            ->assertOk()
            ->assertSee('name="descuento_permanente"', false)
            ->assertSee('value="15"', false)
            ->assertSee('Se aplicará automáticamente a cada línea de las cotizaciones de este cliente. Máximo 50%.');
    });
});

describe('copia congelada en la cotización', function () {
    it('guarda el descuento vigente del cliente e ignora el que mande el navegador', function () {
        $this->actingAs($this->user)->post('/cotizaciones', cotizacionConDescuento($this->cliente, $this->articulo->id, '1', '100.00', '15', [
            'descuento_cliente_porcentaje' => '40',
        ]))->assertSessionHasNoErrors();

        expect(Cotizacion::sole()->descuento_cliente_porcentaje)->toBe('15.00');
    });

    it('cambiar después el descuento del cliente no mueve la cotización ni sus totales', function () {
        $cotizacion = cotizacionEnviadaConDescuento($this->user, $this->cliente, $this->articulo->id);
        $total = $cotizacion->total;

        $this->cliente->update(['descuento_permanente' => '30']);

        expect($cotizacion->fresh()->descuento_cliente_porcentaje)->toBe('15.00')
            ->and($cotizacion->fresh()->total)->toBe($total);
    });

    it('editar sin cambiar de cliente conserva el congelado; cambiando de cliente toma el del nuevo', function () {
        $cotizacion = cotizacionEnviadaConDescuento($this->user, $this->cliente, $this->articulo->id);
        $this->cliente->update(['descuento_permanente' => '20']);

        $this->actingAs($this->user)->put("/cotizaciones/{$cotizacion->id}", cotizacionConDescuento($this->cliente, $this->articulo->id, '3', '333.33', '15'))
            ->assertSessionHasNoErrors();
        expect($cotizacion->fresh()->descuento_cliente_porcentaje)->toBe('15.00');

        $otro = Cliente::factory()->for($this->user)->create(['descuento_permanente' => '30']);
        $this->actingAs($this->user)->put("/cotizaciones/{$cotizacion->id}", cotizacionConDescuento($otro, $this->articulo->id, '3', '333.33', '30'))
            ->assertSessionHasNoErrors();
        expect($cotizacion->fresh()->descuento_cliente_porcentaje)->toBe('30.00');
    });

    it('duplicar para el mismo cliente copia el congelado y las líneas tal cual', function () {
        $original = cotizacionEnviadaConDescuento($this->user, $this->cliente, $this->articulo->id);
        $this->cliente->update(['descuento_permanente' => '40']);

        $this->actingAs($this->user)->post("/cotizaciones/{$original->id}/duplicar", ['cliente_id' => $this->cliente->id]);

        $copia = Cotizacion::whereKeyNot($original->id)->sole();
        expect($copia->descuento_cliente_porcentaje)->toBe('15.00')
            ->and($copia->lineas->sole()->descuento_valor)->toBe('15.00')
            ->and($copia->total)->toBe($original->total);
    });

    it('duplicar para otro cliente aplica su descuento a todas las líneas y recalcula', function () {
        $original = cotizacionEnviadaConDescuento($this->user, $this->cliente, $this->articulo->id, '2', '100.00', '15');
        $otro = Cliente::factory()->for($this->user)->create(['descuento_permanente' => '10']);

        $this->actingAs($this->user)->post("/cotizaciones/{$original->id}/duplicar", ['cliente_id' => $otro->id]);

        $copia = Cotizacion::whereKeyNot($original->id)->sole();
        $linea = $copia->lineas->sole();
        expect($copia->descuento_cliente_porcentaje)->toBe('10.00')
            ->and($linea->descuento_tipo->value)->toBe('porcentaje')
            ->and($linea->descuento_valor)->toBe('10.00')
            ->and($linea->importe)->toBe('180.00')
            ->and($copia->total)->toBe('208.80');
    });

    it('duplicar para un cliente sin descuento quita el descuento de las líneas', function () {
        $original = cotizacionEnviadaConDescuento($this->user, $this->cliente, $this->articulo->id, '2', '100.00', '15');

        $this->actingAs($this->user)->post("/cotizaciones/{$original->id}/duplicar", ['cliente_id' => $this->sinDescuento->id]);

        $copia = Cotizacion::whereKeyNot($original->id)->sole();
        expect($copia->descuento_cliente_porcentaje)->toBe('0.00')
            ->and($copia->lineas->sole()->descuento_tipo)->toBeNull()
            ->and($copia->lineas->sole()->descuento_valor)->toBeNull()
            ->and($copia->total)->toBe('232.00');
    });
});

describe('pantallas de la cotización', function () {
    it('el formulario lleva los descuentos de los clientes para precargar las líneas', function () {
        $respuesta = $this->actingAs($this->user)->get('/cotizaciones/crear')->assertOk();

        $respuesta->assertSee('data-aviso-descuento-cliente', false)
            ->assertSee('alerta-info', false)
            ->assertSee(e(json_encode([$this->cliente->id => ['nombre' => 'FERRETERIA LOPEZ', 'porcentaje' => '15']])), false)
            ->assertSee('Puedes modificarlo línea por línea si esta cotización es una excepción.');
    });

    it('al editar, el aviso arranca con el congelado de la cotización', function () {
        $cotizacion = cotizacionEnviadaConDescuento($this->user, $this->cliente, $this->articulo->id);
        $this->cliente->update(['descuento_permanente' => '30']);

        $this->actingAs($this->user)->get("/cotizaciones/{$cotizacion->id}/editar")
            ->assertOk()
            ->assertSee('data-aviso-descuento-porcentaje>15</span>', false)
            ->assertSee(e(json_encode(['cliente_id' => $this->cliente->id, 'nombre' => 'FERRETERIA LOPEZ', 'porcentaje' => '15'])), false);
    });

    it('la ventana del dashboard también trae el aviso', function () {
        $this->actingAs($this->user)->get('/dashboard')
            ->assertOk()
            ->assertSee('data-aviso-descuento-cliente', false);
    });

    it('factura y orden de compra no precargan descuento', function () {
        $this->actingAs($this->user)->get('/facturas/crear')->assertOk()->assertDontSee('data-aviso-descuento-cliente', false);
        $this->actingAs($this->user)->get('/ordenes-compra/crear')->assertOk()->assertDontSee('data-aviso-descuento-cliente', false);
    });

    it('el detalle muestra el descuento al cotizar y el PDF no', function () {
        $cotizacion = cotizacionEnviadaConDescuento($this->user, $this->cliente, $this->articulo->id);

        $this->actingAs($this->user)->get("/cotizaciones/{$cotizacion->id}")
            ->assertOk()
            ->assertSee('Descuento de cliente al cotizar')
            ->assertSee('<strong>15%</strong>', false);

        $sinDescuento = cotizacionEnviadaConDescuento($this->user, $this->sinDescuento, $this->articulo->id, porcentaje: null);
        $this->actingAs($this->user)->get("/cotizaciones/{$sinDescuento->id}")
            ->assertOk()
            ->assertDontSee('Descuento de cliente al cotizar');

        expect(view('cotizaciones.pdf', app(GeneradorPdfCotizacion::class)->datos($cotizacion))->render())
            ->not->toContain('Descuento de cliente al cotizar')
            ->toContain('15%');
    });
});

describe('precio de facturación', function () {
    it('esconde el descuento de línea en el precio', function (string $cantidad, string $precio, ?string $tipo, ?string $valor, string $esperado) {
        expect(CalculadoraTotalesDocumento::precioConDescuentoDeLinea($cantidad, $precio, $tipo, $valor))->toBe($esperado);
    })->with([
        'el caso de la spec: $333.33 × 3 al 15%' => ['3', '333.33', 'porcentaje', '15', '283.33'],
        'sin descuento es el mismo precio' => ['3', '333.33', null, null, '333.33'],
        'descuento en monto' => ['4', '50.00', 'monto', '20.00', '45.00'],
        // $100.00 entre 3 deja $33.33: la factura suma un centavo menos y no se compensa.
        'residuo de centavos' => ['3', '50.00', 'monto', '50.00', '33.33'],
    ]);

    it('no depende del descuento global', function () {
        $cotizacion = cotizacionEnviadaConDescuento($this->user, $this->cliente, $this->articulo->id, cambios: [
            'descuento_global_tipo' => 'porcentaje', 'descuento_global_valor' => '10',
        ]);

        expect($cotizacion->lineas->sole()->precio_unitario_facturacion)->toBe('283.33');
    });
});

describe('cotización → factura', function () {
    it('el formulario se precarga con el precio rebajado, sin descuento y con el aviso', function () {
        $cotizacion = cotizacionEnviadaConDescuento($this->user, $this->cliente, $this->articulo->id);

        $this->actingAs($this->user)->get("/facturas/crear?cotizacion={$cotizacion->id}")
            ->assertOk()
            ->assertSee('value="283.33"', false)
            ->assertDontSee('value="333.33"', false)
            ->assertDontSee('<option value="porcentaje" selected>', false)
            ->assertSee('data-aviso-descuento-cliente-factura', false)
            ->assertSee('ya incluyen el descuento de <strong>15%</strong>', false);
    });

    it('sin descuento de cliente no hay aviso y el precio no cambia', function () {
        $cotizacion = cotizacionEnviadaConDescuento($this->user, $this->sinDescuento, $this->articulo->id, porcentaje: null);

        $this->actingAs($this->user)->get("/facturas/crear?cotizacion={$cotizacion->id}")
            ->assertOk()
            ->assertSee('value="333.33"', false)
            ->assertDontSee('data-aviso-descuento-cliente-factura', false);
    });

    it('la factura cobra lo mismo que la cotización y no muestra descuento', function () {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado())]);
        $cotizacion = cotizacionEnviadaConDescuento($this->user, $this->cliente, $this->articulo->id);

        $this->actingAs($this->user)->post('/facturas', facturaPrecargada($cotizacion))->assertSessionHasNoErrors();

        $factura = Factura::sole();
        expect($cotizacion->total)->toBe('985.99')
            ->and($cotizacion->total_descuento)->toBe('150.00')
            ->and($factura->total)->toBe('985.99')
            ->and($factura->total_iva_16)->toBe($cotizacion->total_iva_16)
            ->and($factura->total_descuento)->toBe('0.00')
            ->and($factura->subtotal)->toBe('849.99')
            ->and($factura->lineas->sole()->precio_unitario)->toBe('283.33')
            ->and($factura->lineas->sole()->descuento_tipo)->toBeNull();
    });

    it('con descuento global encima, el global viaja visible y el total sigue cuadrando', function () {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado())]);
        $cotizacion = cotizacionEnviadaConDescuento($this->user, $this->cliente, $this->articulo->id, cambios: [
            'descuento_global_tipo' => 'porcentaje', 'descuento_global_valor' => '10',
        ]);

        $this->actingAs($this->user)->post('/facturas', facturaPrecargada($cotizacion))->assertSessionHasNoErrors();

        $factura = Factura::sole();
        expect($factura->total)->toBe($cotizacion->total)
            ->and($factura->descuento_global_valor)->toBe('10.00')
            ->and($factura->total_descuento)->toBe('85.00');
    });

    it('también un descuento de línea capturado a mano se esconde en el precio', function () {
        $cotizacion = cotizacionEnviadaConDescuento($this->user, $this->sinDescuento, $this->articulo->id, '4', '50.00', '25');

        expect($cotizacion->lineas->sole()->datosParaFactura())->toMatchArray([
            'precio_unitario' => '37.50',
            'descuento_tipo' => null,
            'descuento_valor' => null,
        ]);
    });

    it('el timbrado directo desde la vista previa también lo esconde', function () {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado())]);
        $cotizacion = cotizacionEnviadaConDescuento($this->user, $this->cliente, $this->articulo->id);

        $this->actingAs($this->user)
            ->postJson(route('cotizaciones.timbrar', $cotizacion), ['uso_cfdi' => 'G01', 'metodo_pago' => 'PUE', 'forma_pago' => '03'])
            ->assertOk();

        $factura = Factura::sole();
        expect($factura->total)->toBe('985.99')
            ->and($factura->total_descuento)->toBe('0.00')
            ->and($factura->lineas->sole()->precio_unitario)->toBe('283.33');

        Http::assertSent(fn ($peticion) => $peticion['items'][0]['product']['price'] === 283.33 && $peticion['items'][0]['discount'] == 0);
    });

    it('una factura desde cero no aplica el descuento del cliente', function () {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado())]);
        $this->cliente->update(['descuento_permanente' => '30']);

        $this->actingAs($this->user)->post('/facturas', [
            'cliente_id' => $this->cliente->id,
            'uso_cfdi' => 'G03',
            'metodo_pago' => 'PUE',
            'forma_pago' => '03',
            'descuento_global_tipo' => '',
            'descuento_global_valor' => '',
            'lineas' => [['articulo_id' => (string) $this->articulo->id, 'cantidad' => '2', 'descripcion' => 'Sello', 'modelo' => 'S-1', 'precio_unitario' => '100.00', 'descuento_tipo' => '', 'descuento_valor' => '', 'tasa_iva' => '16']],
        ])->assertSessionHasNoErrors();

        $factura = Factura::sole();
        expect($factura->lineas->sole()->precio_unitario)->toBe('100.00')
            ->and($factura->total_descuento)->toBe('0.00')
            ->and($factura->total)->toBe('232.00');
    });
});

describe('cotización aceptada → venta → autofactura', function () {
    beforeEach(function () {
        Mail::fake();
        $this->cotizacion = cotizacionEnviadaConDescuento($this->user, $this->cliente, $this->articulo->id);
        $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/aceptar", ['cliente_nombre' => 'Ana López', 'cliente_telefono' => '449 765 4321', 'cliente_correo' => 'ana@example.com'])
            ->assertSessionHasNoErrors();
        $this->venta = Pedido::sole();
        $cuenta = Cuenta::factory()->for($this->user)->create();
        $this->actingAs($this->user)->post("/pedidos/{$this->venta->id}/pagos", ['cuenta_id' => $cuenta->id, 'fecha_pago' => today('America/Mexico_City')->toDateString(), 'monto' => $this->venta->total]);
        $this->venta->refresh();
        auth()->logout();
    });

    it('la venta conserva el descuento visible, como la cotización', function () {
        $linea = $this->venta->lineas->sole();

        expect($linea->precio_unitario)->toBe('333.33')
            ->and($linea->descuento_valor)->toBe('15.00')
            ->and($this->venta->total)->toBe('985.99');
    });

    it('la autofactura lo esconde en el precio y cobra lo mismo', function () {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado())]);

        $this->post('/autofactura/'.$this->venta->autofactura_token, [
            'rfc' => 'xaxx010101000', 'razon_social' => 'PUBLICO EN GENERAL', 'regimen_fiscal' => '616',
            'codigo_postal_fiscal' => '20000', 'uso_cfdi' => 'S01', 'correo' => 'cliente@example.com',
        ])->assertSessionHasNoErrors();

        $factura = Factura::sole();
        expect($factura->pedido_id)->toBe($this->venta->id)
            ->and($factura->total)->toBe('985.99')
            ->and($factura->total_descuento)->toBe('0.00')
            ->and($factura->lineas->sole()->precio_unitario)->toBe('283.33')
            ->and($factura->lineas->sole()->descuento_tipo)->toBeNull()
            ->and($factura->lineas->sole()->importe)->toBe('849.99');
    });
});
