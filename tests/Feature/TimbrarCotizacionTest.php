<?php

use App\Enums\EstadoCotizacion;
use App\Enums\EstadoFactura;
use App\Models\Articulo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Factura;
use App\Models\User;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use Illuminate\Support\Facades\Http;

/**
 * Cotización facturable con una línea del artículo dado.
 */
function cotizacionParaTimbrar(User $user, Cliente $cliente, Articulo $articulo, EstadoCotizacion $estado = EstadoCotizacion::Enviada): Cotizacion
{
    $cotizacion = Cotizacion::factory()->for($cliente)->enEstado($estado)->create(['user_id' => $user->id]);
    $linea = ['cantidad' => 3, 'precio_unitario' => '80.00', 'tasa_iva' => '16', 'descuento_tipo' => null, 'descuento_valor' => null];
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

/**
 * @param  array<string, mixed>  $cambios
 * @return array<string, mixed>
 */
function datosFiscalesTimbrado(array $cambios = []): array
{
    return ['uso_cfdi' => 'G01', 'metodo_pago' => 'PUE', 'forma_pago' => '03', ...$cambios];
}

beforeEach(function () {
    config([
        'services.facturapi.llave' => 'sk_test_prueba',
        'services.facturapi.emisor' => ['rfc' => 'EKU9003173C9', 'razon_social' => 'ESCUELA KEMPER URGATE', 'regimen_fiscal' => '601', 'codigo_postal' => '26015'],
    ]);

    $this->user = User::factory()->create();
    $this->cliente = Cliente::factory()->for($this->user)->create();
    $this->articulo = articuloFacturable($this->user);
    $this->cotizacion = cotizacionParaTimbrar($this->user, $this->cliente, $this->articulo);
});

describe('timbrado con JavaScript', function () {
    it('crea la factura con los datos de la cotización, la timbra y devuelve las filas', function () {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado())]);

        $respuesta = $this->actingAs($this->user)
            ->postJson(route('cotizaciones.timbrar', $this->cotizacion), datosFiscalesTimbrado())
            ->assertOk()
            ->assertJsonPath('tipo', 'exito');

        $factura = Factura::sole()->load('lineas');

        expect($factura->cotizacion_id)->toBe($this->cotizacion->id)
            ->and($factura->cliente_id)->toBe($this->cliente->id)
            ->and($factura->estado)->toBe(EstadoFactura::Timbrada)
            ->and($factura->uso_cfdi->value)->toBe('G01')
            ->and($factura->forma_pago->value)->toBe('03')
            ->and($factura->total)->toBe($this->cotizacion->total)
            ->and($factura->lineas->sole()->articulo_id)->toBe($this->articulo->id)
            ->and($factura->lineas->sole()->precio_unitario)->toBe('80.00')
            ->and($this->cotizacion->fresh()->estaFacturada())->toBeTrue();

        expect($respuesta->json('mensaje'))->toContain('Factura timbrada')
            ->and($respuesta->json('factura'))->toBe($factura->id)
            ->and($respuesta->json('fila'))->toContain('data-factura="'.$factura->id.'"')
            ->and($respuesta->json('fila'))->toContain(route('facturas.vista-previa', $factura))
            ->and($respuesta->json('filaCotizacion'))->toContain('data-cotizacion="'.$this->cotizacion->id.'"')
            ->and($respuesta->json('filaCotizacion'))->toContain('Facturada');
    });

    it('ignora el cliente y las líneas que mande el navegador', function () {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado())]);
        $otro = Cliente::factory()->for($this->user)->create();

        $this->actingAs($this->user)->postJson(route('cotizaciones.timbrar', $this->cotizacion), datosFiscalesTimbrado([
            'cliente_id' => $otro->id,
            'lineas' => [['articulo_id' => $this->articulo->id, 'cantidad' => 99, 'descripcion' => 'X', 'modelo' => 'X', 'precio_unitario' => '1.00', 'tasa_iva' => '16']],
        ]))->assertOk();

        expect(Factura::sole()->cliente_id)->toBe($this->cliente->id)
            ->and(Factura::sole()->lineas()->sole()->cantidad)->toBe(3);
    });

    it('rechaza datos fiscales inválidos sin crear la factura', function (array $cambios, string $campo) {
        Http::fake();

        $this->actingAs($this->user)
            ->postJson(route('cotizaciones.timbrar', $this->cotizacion), datosFiscalesTimbrado($cambios))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($campo);

        expect(Factura::count())->toBe(0);
        Http::assertNothingSent();
    })->with([
        'sin forma de pago' => [['forma_pago' => ''], 'forma_pago'],
        '99 con PUE' => [['forma_pago' => '99'], 'forma_pago'],
        'uso de nómina' => [['uso_cfdi' => 'CN01'], 'uso_cfdi'],
    ]);

    it('no crea una segunda factura de una cotización ya facturada', function () {
        Http::fake();
        $vigente = Factura::factory()->for($this->cliente)->create(['user_id' => $this->user->id, 'cotizacion_id' => $this->cotizacion->id]);

        $this->actingAs($this->user)
            ->postJson(route('cotizaciones.timbrar', $this->cotizacion), datosFiscalesTimbrado())
            ->assertStatus(409)
            ->assertJsonPath('mensaje', "Esta cotización ya se facturó en {$vigente->folioVisible()}.")
            ->assertJsonPath('url', route('facturas.show', $vigente));

        expect(Factura::count())->toBe(1);
        Http::assertNothingSent();
    });

    it('no timbra una cotización en borrador', function () {
        Http::fake();
        $borrador = cotizacionParaTimbrar($this->user, $this->cliente, $this->articulo, EstadoCotizacion::Borrador);

        $this->actingAs($this->user)
            ->postJson(route('cotizaciones.timbrar', $borrador), datosFiscalesTimbrado())
            ->assertUnprocessable()
            ->assertJsonPath('mensaje', $borrador->motivoNoFacturable());

        expect(Factura::count())->toBe(0);
    });

    it('responde 404 con una cotización ajena', function () {
        Http::fake();
        $ajena = Cotizacion::factory()->conLinea()->enEstado(EstadoCotizacion::Enviada)->create();

        $this->actingAs($this->user)
            ->postJson(route('cotizaciones.timbrar', $ajena), datosFiscalesTimbrado())
            ->assertNotFound();

        expect(Factura::count())->toBe(0);
    });

    it('con el timbrado fallido devuelve la factura pendiente y el error', function () {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(['message' => 'Servicio no disponible'], 503)]);

        $respuesta = $this->actingAs($this->user)
            ->postJson(route('cotizaciones.timbrar', $this->cotizacion), datosFiscalesTimbrado())
            ->assertOk()
            ->assertJsonPath('tipo', 'error');

        expect(Factura::sole()->estado)->toBe(EstadoFactura::Pendiente)
            ->and($respuesta->json('mensaje'))->toContain('No se pudo timbrar')
            ->and($respuesta->json('fila'))->toContain('data-factura="'.Factura::sole()->id.'"')
            ->and($this->cotizacion->fresh()->estaFacturada())->toBeTrue();
    });
});

it('sin JavaScript timbra y lleva al detalle de la factura', function () {
    Http::fake(['www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado())]);

    $this->actingAs($this->user)
        ->post(route('cotizaciones.timbrar', $this->cotizacion), datosFiscalesTimbrado())
        ->assertRedirect(route('facturas.show', Factura::sole()))
        ->assertSessionHas('exito');
});

describe('botón y ventana', function () {
    it('la vista previa de una cotización facturable abre la confirmación', function () {
        $this->actingAs($this->user)->get(route('cotizaciones.vista-previa', $this->cotizacion))
            ->assertSee('href="#dialogo-timbrar"', false)
            ->assertSee('aria-label="Timbrar factura"', false)
            ->assertSee('action="'.route('cotizaciones.timbrar', $this->cotizacion).'"', false)
            ->assertSee('¿Timbrar la factura de '.$this->cotizacion->folio_formateado.'?')
            ->assertSee(route('facturas.create', ['cotizacion' => $this->cotizacion->id]), false)
            ->assertDontSee('<a href="'.route('facturas.create', ['cotizacion' => $this->cotizacion->id]).'" class="boton boton-secundario', false);
    });

    it('avisa en la confirmación de los precios que cambiaron en el catálogo', function () {
        $this->actingAs($this->user)->get(route('cotizaciones.vista-previa', $this->cotizacion))
            ->assertSee('$80.00 en la cotización', false);
    });

    it('no ofrece timbrar una cotización en borrador o ya facturada', function () {
        $borrador = cotizacionParaTimbrar($this->user, $this->cliente, $this->articulo, EstadoCotizacion::Borrador);
        Factura::factory()->for($this->cliente)->create(['user_id' => $this->user->id, 'cotizacion_id' => $this->cotizacion->id]);

        foreach ([$borrador, $this->cotizacion] as $cotizacion) {
            $this->actingAs($this->user)->get(route('cotizaciones.vista-previa', $cotizacion))
                ->assertDontSee('dialogo-timbrar', false);
        }
    });

    it('el dashboard y la bandeja cargan el script y los avisos del timbrado', function (string $ruta) {
        $this->actingAs($this->user)->get($ruta)
            ->assertSee(asset('js/timbrar-cotizacion.js'), false)
            ->assertSee('data-timbrado-aviso-exito', false);
    })->with(['/dashboard', '/cotizaciones']);

    it('el detalle de la cotización sigue llevando al formulario de factura', function () {
        $this->actingAs($this->user)->get(route('cotizaciones.show', $this->cotizacion))
            ->assertSee(route('facturas.create', ['cotizacion' => $this->cotizacion->id]), false)
            ->assertDontSee('dialogo-timbrar', false);
    });
});
