<?php

use App\Enums\EstadoCotizacion;
use App\Enums\EstadoFactura;
use App\Enums\FormaPago;
use App\Enums\MetodoPago;
use App\Enums\UsoCfdi;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Factura;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.facturapi.llave' => 'sk_test_prueba',
        'services.facturapi.emisor' => ['rfc' => 'EKU9003173C9', 'razon_social' => 'ESCUELA KEMPER URGATE', 'regimen_fiscal' => '601', 'codigo_postal' => '26015'],
    ]);

    $this->user = User::factory()->create();
    $this->cliente = Cliente::factory()->for($this->user)->create();
    $this->otroCliente = Cliente::factory()->for($this->user)->create(['razon_social' => 'PAPELERIA SOL']);
});

describe('duplicar cotización', function () {
    it('crea la copia en borrador para el cliente elegido, con las mismas líneas y sin pagos', function () {
        $original = Cotizacion::factory()->for($this->cliente)->conLinea(3, '50.00')->enEstado(EstadoCotizacion::Pagada)
            ->create(['user_id' => $this->user->id, 'descuento_global_tipo' => 'porcentaje', 'descuento_global_valor' => '5']);
        $original->lineas()->update(['costo_unitario' => '20.00']);
        $original->pagos()->create(['tipo' => 'pago_total', 'fecha_pago' => today(), 'monto' => '174.00', 'forma_pago' => '03']);

        $respuesta = $this->actingAs($this->user)->post("/cotizaciones/{$original->id}/duplicar", ['cliente_id' => $this->otroCliente->id]);

        $copia = Cotizacion::whereKeyNot($original->id)->sole();
        $respuesta->assertRedirect(route('cotizaciones.show', $copia))
            ->assertSessionHas('exito', "Se creó {$copia->folio_formateado} como copia de {$original->folio_formateado} para PAPELERIA SOL.");

        expect($copia->cliente_id)->toBe($this->otroCliente->id)
            ->and($copia->duplicada_de_id)->toBe($original->id)
            ->and($copia->estado)->toBe(EstadoCotizacion::Borrador)
            ->and($copia->folio)->toBe($original->folio + 1)
            ->and($copia->descuento_global_valor)->toBe('5.00')
            ->and($copia->total)->toBe($original->total)
            ->and($copia->pagos)->toBeEmpty()
            ->and($copia->lineas->sole()->costo_unitario)->toBe('20.00');
    });

    it('exige un cliente propio y activo', function (Closure $cliente) {
        $original = Cotizacion::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->post("/cotizaciones/{$original->id}/duplicar", ['cliente_id' => $cliente($this)])
            ->assertSessionHasErrorsIn('duplicar', ['cliente_id' => 'Elige el cliente de la copia.']);

        expect(Cotizacion::count())->toBe(1);
    })->with([
        'sin cliente' => [fn () => ''],
        'ajeno' => [fn () => Cliente::factory()->create()->id],
        'eliminado' => [function ($prueba) {
            $eliminado = Cliente::factory()->for($prueba->user)->create();
            $eliminado->delete();

            return $eliminado->id;
        }],
    ]);

    it('una cotización facturada se duplica y la copia nace sin factura', function () {
        $original = Cotizacion::factory()->for($this->cliente)->conLinea()->enEstado(EstadoCotizacion::Enviada)->create(['user_id' => $this->user->id]);
        Factura::factory()->for($this->cliente)->create(['user_id' => $this->user->id, 'cotizacion_id' => $original->id]);

        $this->actingAs($this->user)->post("/cotizaciones/{$original->id}/duplicar", ['cliente_id' => $this->cliente->id]);

        $copia = Cotizacion::whereKeyNot($original->id)->sole();
        expect($copia->estaFacturada())->toBeFalse()
            ->and($copia->esEditable())->toBeTrue();
    });

    it('el detalle abre la ventana con el cliente original preseleccionado y la copia dice de dónde salió', function () {
        $original = Cotizacion::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);
        $this->actingAs($this->user)->post("/cotizaciones/{$original->id}/duplicar", ['cliente_id' => $this->otroCliente->id]);
        $copia = Cotizacion::whereKeyNot($original->id)->sole();

        $this->actingAs($this->user)->get("/cotizaciones/{$original->id}")
            ->assertSee('id="dialogo-duplicar"', false)
            ->assertSee('action="'.route('cotizaciones.duplicar', $original).'"', false)
            ->assertSee('<option value="'.$this->cliente->id.'" selected>', false);

        $this->actingAs($this->user)->get("/cotizaciones/{$copia->id}")
            ->assertSee('Duplicada de')
            ->assertSee(route('cotizaciones.show', $original));
    });
});

describe('duplicar factura', function () {
    beforeEach(function () {
        $this->original = Factura::factory()->for($this->cliente)->timbrada()->conLinea(2, '75.00')->ppd()->create([
            'user_id' => $this->user->id,
            'uso_cfdi' => UsoCfdi::GastosEnGeneral,
            'descuento_global_tipo' => 'monto',
            'descuento_global_valor' => '10.00',
        ]);
    });

    it('abre el formulario lleno con el cliente elegido, los datos fiscales y las líneas, en cualquier estado', function (EstadoFactura $estado) {
        $this->original->forceFill(['estado' => $estado])->save();

        $this->actingAs($this->user)->get("/facturas/crear?duplicar={$this->original->id}&cliente_id={$this->otroCliente->id}")
            ->assertOk()
            ->assertSee('<input type="hidden" name="duplicada_de_id" value="'.$this->original->id.'">', false)
            ->assertSee('<option value="'.$this->otroCliente->id.'" selected>', false)
            ->assertSee('<option value="'.MetodoPago::Diferido->value.'" selected>', false)
            ->assertSee('<option value="'.FormaPago::PorDefinir->value.'" selected>', false)
            ->assertSee('<option value="monto" selected>', false)
            ->assertSee('value="75.00"', false)
            ->assertSee('Copia de la factura')
            ->assertDontSee('name="cotizacion_id"', false);

        expect(Factura::count())->toBe(1);
    })->with([EstadoFactura::Timbrada, EstadoFactura::Cancelada, EstadoFactura::Pendiente]);

    it('deja el cliente sin elegir si es ajeno', function () {
        $ajeno = Cliente::factory()->create();

        $this->actingAs($this->user)->get("/facturas/crear?duplicar={$this->original->id}&cliente_id={$ajeno->id}")
            ->assertOk()
            ->assertDontSee('<option value="'.$ajeno->id.'" selected>', false)
            ->assertDontSee('" selected>'.$this->cliente->razon_social, false);
    });

    it('omite con aviso las líneas cuyo artículo ya no existe', function () {
        $this->original->lineas->sole()->articulo->delete();

        $this->actingAs($this->user)->get("/facturas/crear?duplicar={$this->original->id}&cliente_id={$this->cliente->id}")
            ->assertOk()
            ->assertSee('data-lineas-omitidas', false)
            ->assertSee('Se omitió 1 línea porque su artículo ya no existe: Sello de prueba.')
            ->assertDontSee('value="75.00"', false);
    });

    it('al timbrar guarda de qué factura salió y no la vincula a ninguna cotización', function () {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado())]);
        $linea = $this->original->lineas->sole();

        $this->actingAs($this->user)->post('/facturas', [
            'duplicada_de_id' => $this->original->id,
            'cliente_id' => $this->otroCliente->id,
            'uso_cfdi' => 'G03',
            'metodo_pago' => 'PUE',
            'forma_pago' => '03',
            'lineas' => [[
                'articulo_id' => (string) $linea->articulo_id,
                'cantidad' => '2',
                'descripcion' => $linea->descripcion,
                'modelo' => $linea->modelo,
                'precio_unitario' => '75.00',
                'descuento_tipo' => '',
                'descuento_valor' => '',
                'tasa_iva' => '16',
            ]],
        ])->assertSessionHasNoErrors();

        $copia = Factura::whereKeyNot($this->original->id)->sole();
        expect($copia->duplicada_de_id)->toBe($this->original->id)
            ->and($copia->cotizacion_id)->toBeNull()
            ->and($copia->cliente_id)->toBe($this->otroCliente->id)
            ->and($copia->folio)->toBe($this->original->folio + 1);

        $this->actingAs($this->user)->get("/facturas/{$copia->id}")
            ->assertSee('Duplicada de')
            ->assertSee(route('facturas.show', $this->original));
    });

    it('rechaza como origen una factura ajena', function () {
        Http::fake();
        $ajena = Factura::factory()->conLinea()->create();
        $linea = $this->original->lineas->sole();

        $this->actingAs($this->user)->post('/facturas', [
            'duplicada_de_id' => $ajena->id,
            'cliente_id' => $this->cliente->id,
            'uso_cfdi' => 'G03',
            'metodo_pago' => 'PUE',
            'forma_pago' => '03',
            'lineas' => [[
                'articulo_id' => (string) $linea->articulo_id, 'cantidad' => '1', 'descripcion' => 'X', 'modelo' => 'P-1',
                'precio_unitario' => '10.00', 'descuento_tipo' => '', 'descuento_valor' => '', 'tasa_iva' => '16',
            ]],
        ])->assertSessionHasErrors('duplicada_de_id');

        Http::assertNothingSent();
    });

    it('responde 404 al duplicar una factura ajena', function () {
        $ajena = Factura::factory()->conLinea()->create();

        $this->actingAs($this->user)->get("/facturas/crear?duplicar={$ajena->id}&cliente_id={$this->cliente->id}")->assertNotFound();
    });

    it('el detalle ofrece Duplicar con el cliente original preseleccionado', function () {
        $this->actingAs($this->user)->get("/facturas/{$this->original->id}")
            ->assertSee('id="dialogo-duplicar"', false)
            ->assertSee('<form method="GET" action="'.route('facturas.create').'">', false)
            ->assertSee('<input type="hidden" name="duplicar" value="'.$this->original->id.'">', false)
            ->assertSee('<option value="'.$this->cliente->id.'" selected>', false);
    });
});
