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
 * Cotización con una línea del artículo dado (precio cotizado = el del
 * catálogo, salvo que se indique otro).
 */
function cotizacionDeArticulo(User $user, Cliente $cliente, Articulo $articulo, EstadoCotizacion $estado = EstadoCotizacion::Enviada, ?string $precio = null): Cotizacion
{
    $cotizacion = Cotizacion::factory()->for($cliente)->enEstado($estado)->create(['user_id' => $user->id]);
    $linea = ['cantidad' => 2, 'precio_unitario' => $precio ?? $articulo->precio_unitario_sin_iva, 'tasa_iva' => '16', 'descuento_tipo' => null, 'descuento_valor' => null];
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
 * Lo que manda el formulario lleno con la cotización al pulsar "Generar y
 * timbrar".
 *
 * @return array<string, mixed>
 */
function facturaDesdeCotizacion(Cotizacion $cotizacion, array $cambios = []): array
{
    return [
        'cotizacion_id' => $cotizacion->id,
        'cliente_id' => $cotizacion->cliente_id,
        'uso_cfdi' => 'G03',
        'metodo_pago' => 'PUE',
        'forma_pago' => '03',
        'descuento_global_tipo' => '',
        'descuento_global_valor' => '',
        'lineas' => $cotizacion->lineas->map(fn ($linea) => [
            'articulo_id' => (string) $linea->articulo_id,
            'cantidad' => (string) $linea->cantidad,
            'descripcion' => $linea->descripcion,
            'modelo' => $linea->modelo,
            'precio_unitario' => $linea->precio_unitario,
            'descuento_tipo' => '',
            'descuento_valor' => '',
            'tasa_iva' => '16',
        ])->all(),
        ...$cambios,
    ];
}

beforeEach(function () {
    config([
        'services.facturapi.llave' => 'sk_test_prueba',
        'services.facturapi.emisor' => ['rfc' => 'EKU9003173C9', 'razon_social' => 'ESCUELA KEMPER URGATE', 'regimen_fiscal' => '601', 'codigo_postal' => '26015'],
    ]);

    $this->user = User::factory()->create();
    $this->cliente = Cliente::factory()->for($this->user)->create();
    $this->articulo = articuloFacturable($this->user);
});

describe('formulario lleno', function () {
    it('llena cliente, líneas y descuento con la cotización y la marca como origen', function () {
        $cotizacion = cotizacionDeArticulo($this->user, $this->cliente, $this->articulo);
        $cotizacion->update(['descuento_global_tipo' => 'porcentaje', 'descuento_global_valor' => '5']);

        $this->actingAs($this->user)->get("/facturas/crear?cotizacion={$cotizacion->id}")
            ->assertOk()
            ->assertSee('<input type="hidden" name="cotizacion_id" value="'.$cotizacion->id.'">', false)
            ->assertSee('<option value="'.$this->cliente->id.'" selected>', false)
            ->assertSee('<option value="porcentaje" selected>', false)
            ->assertSee('value="'.$this->articulo->id.'"', false)
            ->assertSee($this->articulo->nombre)
            ->assertSee($cotizacion->folio_formateado)
            ->assertDontSee('data-aviso-precios', false);
    });

    it('avisa si el precio del catálogo cambió y conserva el cotizado', function () {
        $cotizacion = cotizacionDeArticulo($this->user, $this->cliente, $this->articulo, precio: '80.00');

        $this->actingAs($this->user)->get("/facturas/crear?cotizacion={$cotizacion->id}")
            ->assertOk()
            ->assertSee('data-aviso-precios', false)
            ->assertSee('$80.00 en la cotización')
            ->assertSee('value="80.00"', false);
    });

    it('regresa al detalle con el motivo si no se puede facturar', function (Closure $preparar, string $motivo) {
        $cotizacion = $preparar($this);

        $this->actingAs($this->user)->get("/facturas/crear?cotizacion={$cotizacion->id}")
            ->assertRedirect(route('cotizaciones.show', $cotizacion))
            ->assertSessionHas('error', fn (string $mensaje) => str_contains($mensaje, $motivo));
    })->with([
        'en borrador' => [fn ($prueba) => cotizacionDeArticulo($prueba->user, $prueba->cliente, $prueba->articulo, EstadoCotizacion::Borrador), 'no se puede facturar'],
        'ya facturada' => [function ($prueba) {
            $cotizacion = cotizacionDeArticulo($prueba->user, $prueba->cliente, $prueba->articulo);
            Factura::factory()->for($prueba->cliente)->create(['user_id' => $prueba->user->id, 'cotizacion_id' => $cotizacion->id]);

            return $cotizacion;
        }, 'ya tiene la factura'],
        'con línea libre' => [fn ($prueba) => Cotizacion::factory()->for($prueba->cliente)->conLinea()->enEstado(EstadoCotizacion::Enviada)->create(['user_id' => $prueba->user->id]), 'no vienen del catálogo (1)'],
        'con artículo eliminado' => [function ($prueba) {
            $cotizacion = cotizacionDeArticulo($prueba->user, $prueba->cliente, $prueba->articulo);
            $prueba->articulo->delete();

            return $cotizacion;
        }, 'no vienen del catálogo (1)'],
    ]);

    it('responde 404 con una cotización ajena', function () {
        $ajena = Cotizacion::factory()->conLinea()->enEstado(EstadoCotizacion::Enviada)->create();

        $this->actingAs($this->user)->get("/facturas/crear?cotizacion={$ajena->id}")->assertNotFound();
    });
});

describe('guardar', function () {
    it('guarda el vínculo y la cotización queda facturada sin cambiar su estado ni sus pagos', function () {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado())]);
        $cotizacion = cotizacionDeArticulo($this->user, $this->cliente, $this->articulo, EstadoCotizacion::Pagada);

        $this->actingAs($this->user)->post('/facturas', facturaDesdeCotizacion($cotizacion));

        $factura = Factura::sole();
        expect($factura->cotizacion_id)->toBe($cotizacion->id)
            ->and($factura->estado)->toBe(EstadoFactura::Timbrada)
            ->and($cotizacion->fresh()->estaFacturada())->toBeTrue()
            ->and($cotizacion->fresh()->estado)->toBe(EstadoCotizacion::Pagada)
            ->and($cotizacion->fresh()->esFacturable())->toBeFalse();
    });

    it('permite cambiar líneas y montos y conserva el vínculo', function () {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado())]);
        $cotizacion = cotizacionDeArticulo($this->user, $this->cliente, $this->articulo);
        $datos = facturaDesdeCotizacion($cotizacion);
        $datos['lineas'][0]['precio_unitario'] = '10.00';

        $this->actingAs($this->user)->post('/facturas', $datos)->assertSessionHasNoErrors();

        expect(Factura::sole()->cotizacion_id)->toBe($cotizacion->id)
            ->and(Factura::sole()->total)->not->toBe($cotizacion->total);
    });

    it('no crea una segunda factura de la misma cotización: lleva a la primera', function () {
        Http::fake();
        $cotizacion = cotizacionDeArticulo($this->user, $this->cliente, $this->articulo);
        $primera = Factura::factory()->for($this->cliente)->create(['user_id' => $this->user->id, 'cotizacion_id' => $cotizacion->id]);

        $this->actingAs($this->user)->post('/facturas', facturaDesdeCotizacion($cotizacion))
            ->assertRedirect(route('facturas.show', $primera))
            ->assertSessionHas('error', "Esta cotización ya se facturó en {$primera->folioVisible()}.");

        expect(Factura::count())->toBe(1);
        Http::assertNothingSent();
    });

    it('rechaza una cotización ajena como origen', function () {
        Http::fake();
        $ajena = Cotizacion::factory()->enEstado(EstadoCotizacion::Enviada)->create();
        $propia = cotizacionDeArticulo($this->user, $this->cliente, $this->articulo);

        $this->actingAs($this->user)->post('/facturas', facturaDesdeCotizacion($propia, ['cotizacion_id' => $ajena->id]))
            ->assertSessionHasErrors('cotizacion_id');

        expect(Factura::count())->toBe(0);
    });

    it('con el timbrado fallido la cotización sigue facturada', function () {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(['message' => 'Servicio no disponible'], 503)]);
        $cotizacion = cotizacionDeArticulo($this->user, $this->cliente, $this->articulo);

        $this->actingAs($this->user)->post('/facturas', facturaDesdeCotizacion($cotizacion));

        expect(Factura::sole()->estado)->toBe(EstadoFactura::Pendiente)
            ->and($cotizacion->fresh()->estaFacturada())->toBeTrue();
    });

    it('vuelve a ser facturable si su factura se elimina o se cancela', function (string $fin) {
        $cotizacion = cotizacionDeArticulo($this->user, $this->cliente, $this->articulo);
        $factura = Factura::factory()->for($this->cliente)->enEstado(EstadoFactura::Pendiente)->create(['user_id' => $this->user->id, 'cotizacion_id' => $cotizacion->id]);

        expect($cotizacion->fresh()->esFacturable())->toBeFalse();

        if ($fin === 'eliminar') {
            $this->actingAs($this->user)->delete("/facturas/{$factura->id}");
        } else {
            $factura->forceFill(['estado' => EstadoFactura::Cancelada])->save();
        }

        expect($cotizacion->fresh()->esFacturable())->toBeTrue()
            ->and($cotizacion->fresh()->motivoNoFacturable())->toBeNull();
    })->with(['eliminar', 'cancelar']);
});

describe('cotización facturada', function () {
    beforeEach(function () {
        $this->cotizacion = cotizacionDeArticulo($this->user, $this->cliente, $this->articulo);
        $this->factura = Factura::factory()->for($this->cliente)->timbrada()->create(['user_id' => $this->user->id, 'cotizacion_id' => $this->cotizacion->id]);
    });

    it('no se edita ni se elimina', function () {
        $this->actingAs($this->user)->get("/cotizaciones/{$this->cotizacion->id}/editar")->assertForbidden();
        $this->actingAs($this->user)->delete("/cotizaciones/{$this->cotizacion->id}")
            ->assertSessionHas('error', 'Una cotización facturada no se puede eliminar.');

        expect(Cotizacion::find($this->cotizacion->id))->not->toBeNull();
    });

    it('sí registra pagos', function () {
        $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/pagos", [
            'tipo' => 'pago_total', 'fecha_pago' => now(config('app.zona_negocio'))->toDateString(), 'forma_pago' => '03',
        ])->assertSessionHasNoErrors();

        expect($this->cotizacion->fresh()->estado)->toBe(EstadoCotizacion::Pagada);
    });

    it('no caduca aunque esté enviada y sin movimiento', function () {
        Cotizacion::whereKey($this->cotizacion->id)->update(['updated_at' => now()->subDays(Cotizacion::DIAS_CADUCIDAD + 5)]);

        expect(Cotizacion::porCaducar()->count())->toBe(0)
            ->and(Cotizacion::vencidas()->count())->toBe(0)
            ->and($this->cotizacion->fresh()->mostrarAvisoCaducidad())->toBeFalse();

        $this->artisan('cotizaciones:purgar-vencidas')->assertSuccessful();

        expect(Cotizacion::find($this->cotizacion->id))->not->toBeNull();
    });

    it('el detalle muestra "Facturada" con liga y no ofrece Facturar ni Editar', function () {
        $this->actingAs($this->user)->get("/cotizaciones/{$this->cotizacion->id}")
            ->assertOk()
            ->assertSee('Facturada · '.$this->factura->folioVisible())
            ->assertSee(route('facturas.show', $this->factura))
            ->assertDontSee(route('facturas.create', ['cotizacion' => $this->cotizacion->id]))
            ->assertDontSee(route('cotizaciones.edit', $this->cotizacion));
    });

    it('la factura muestra su origen', function () {
        $this->actingAs($this->user)->get("/facturas/{$this->factura->id}")
            ->assertOk()
            ->assertSee('Origen')
            ->assertSee(route('cotizaciones.show', $this->cotizacion));
    });
});

describe('detalle de la cotización', function () {
    it('ofrece Facturar solo cuando se puede', function () {
        $facturable = cotizacionDeArticulo($this->user, $this->cliente, $this->articulo);
        $borrador = cotizacionDeArticulo($this->user, $this->cliente, $this->articulo, EstadoCotizacion::Borrador);

        $this->actingAs($this->user)->get("/cotizaciones/{$facturable->id}")
            ->assertSee(route('facturas.create', ['cotizacion' => $facturable->id]));
        $this->actingAs($this->user)->get("/cotizaciones/{$borrador->id}")
            ->assertDontSee(route('facturas.create', ['cotizacion' => $borrador->id]));
    });

    it('con líneas libres avisa cuáles corregir en lugar de ofrecer Facturar', function () {
        $cotizacion = Cotizacion::factory()->for($this->cliente)->conLinea()->enEstado(EstadoCotizacion::Enviada)->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->get("/cotizaciones/{$cotizacion->id}")
            ->assertSee('no vienen del catálogo (1)')
            ->assertDontSee(route('facturas.create', ['cotizacion' => $cotizacion->id]));
    });
});

describe('desde cotización', function () {
    it('lista solo las cotizaciones propias por facturar y sin líneas libres', function () {
        $facturable = cotizacionDeArticulo($this->user, $this->cliente, $this->articulo);
        $borrador = cotizacionDeArticulo($this->user, $this->cliente, $this->articulo, EstadoCotizacion::Borrador);
        $facturada = cotizacionDeArticulo($this->user, $this->cliente, $this->articulo, EstadoCotizacion::Pagada);
        Factura::factory()->for($this->cliente)->create(['user_id' => $this->user->id, 'cotizacion_id' => $facturada->id]);
        $libre = Cotizacion::factory()->for($this->cliente)->conLinea()->enEstado(EstadoCotizacion::Enviada)->create(['user_id' => $this->user->id]);
        $ajena = Cotizacion::factory()->conLinea()->enEstado(EstadoCotizacion::Enviada)->create();

        $respuesta = $this->actingAs($this->user)->get('/facturas/cotizaciones')->assertOk();

        $respuesta->assertSee(route('facturas.create', ['cotizacion' => $facturable->id]));

        foreach ([$borrador, $facturada, $libre, $ajena] as $excluida) {
            $respuesta->assertDontSee(route('facturas.create', ['cotizacion' => $excluida->id]));
        }
    });

    it('filtra por folio o cliente y responde solo la lista con ?fragmento=1', function () {
        $buscada = cotizacionDeArticulo($this->user, $this->cliente, $this->articulo);
        $otra = cotizacionDeArticulo($this->user, Cliente::factory()->for($this->user)->create(['razon_social' => 'ZAPATERIA LUNA']), $this->articulo);

        $this->actingAs($this->user)->get('/facturas/cotizaciones?fragmento=1&q='.$buscada->folio_formateado, cabecerasAjax())
            ->assertOk()
            ->assertDontSee('<html', false)
            ->assertSee(route('facturas.create', ['cotizacion' => $buscada->id]))
            ->assertDontSee(route('facturas.create', ['cotizacion' => $otra->id]));

        $this->actingAs($this->user)->get('/facturas/cotizaciones?fragmento=1&q=zapateria', cabecerasAjax())
            ->assertSee(route('facturas.create', ['cotizacion' => $otra->id]))
            ->assertDontSee(route('facturas.create', ['cotizacion' => $buscada->id]));
    });

    it('sin resultados lo dice', function () {
        $this->actingAs($this->user)->get('/facturas/cotizaciones')->assertSee('No hay cotizaciones por facturar');
    });

    it('el listado de facturas ofrece "Desde cotización"', function () {
        $this->actingAs($this->user)->get('/facturas')
            ->assertSee('Desde cotización')
            ->assertSee(route('facturas.cotizaciones'));
    });
});

describe('bandeja de cotizaciones', function () {
    it('filtra por las etiquetas Facturadas y Por facturar', function () {
        $facturada = cotizacionDeArticulo($this->user, $this->cliente, $this->articulo);
        Factura::factory()->for($this->cliente)->create(['user_id' => $this->user->id, 'cotizacion_id' => $facturada->id]);
        $porFacturar = cotizacionDeArticulo($this->user, $this->cliente, $this->articulo, EstadoCotizacion::Pagada);
        $borrador = cotizacionDeArticulo($this->user, $this->cliente, $this->articulo, EstadoCotizacion::Borrador);
        $cancelada = cotizacionDeArticulo($this->user, $this->cliente, $this->articulo);
        Factura::factory()->for($this->cliente)->enEstado(EstadoFactura::Cancelada)->create(['user_id' => $this->user->id, 'cotizacion_id' => $cancelada->id]);

        $this->actingAs($this->user)->get('/cotizaciones?estado=facturadas')
            ->assertSee('data-cotizacion="'.$facturada->id.'"', false)
            ->assertDontSee('data-cotizacion="'.$porFacturar->id.'"', false)
            ->assertDontSee('data-cotizacion="'.$cancelada->id.'"', false)
            ->assertSee('etiqueta-facturada', false);

        $this->actingAs($this->user)->get('/cotizaciones?estado=por_facturar')
            ->assertSee('data-cotizacion="'.$porFacturar->id.'"', false)
            ->assertSee('data-cotizacion="'.$cancelada->id.'"', false)
            ->assertDontSee('data-cotizacion="'.$facturada->id.'"', false)
            ->assertDontSee('data-cotizacion="'.$borrador->id.'"', false);
    });

    it('muestra las etiquetas nuevas y combina con carpeta y búsqueda', function () {
        $facturada = cotizacionDeArticulo($this->user, $this->cliente, $this->articulo);
        Factura::factory()->for($this->cliente)->create(['user_id' => $this->user->id, 'cotizacion_id' => $facturada->id]);
        Cotizacion::whereKey($facturada->id)->update(['created_at' => now()->subMonths(3)]);

        $this->actingAs($this->user)->get('/cotizaciones')
            ->assertSee('Facturadas')
            ->assertSee('Por facturar');

        $this->actingAs($this->user)->get('/cotizaciones?estado=facturadas')
            ->assertDontSee('data-cotizacion="'.$facturada->id.'"', false);

        $this->actingAs($this->user)->get('/cotizaciones?estado=facturadas&periodo=todas&q='.urlencode($this->cliente->razon_social))
            ->assertSee('data-cotizacion="'.$facturada->id.'"', false);
    });
});
