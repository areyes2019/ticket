<?php

use App\Enums\EstadoCotizacion;
use App\Enums\EstadoFactura;
use App\Enums\MotivoCancelacion;
use App\Enums\TipoCuenta;
use App\Enums\TipoPago;
use App\Http\Middleware\CandadoMostrador;
use App\Models\Articulo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\Pedido;
use App\Models\User;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use Illuminate\Support\Facades\Http;

/**
 * Cotización con una línea del artículo (3 × $80), lista para facturar si
 * está enviada.
 */
function cotizacionDelMostrador(User $user, Cliente $cliente, Articulo $articulo, EstadoCotizacion $estado = EstadoCotizacion::Enviada, array $atributos = []): Cotizacion
{
    $cotizacion = Cotizacion::factory()->for($cliente)->enEstado($estado)->create(['user_id' => $user->id, ...$atributos]);
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
        'costo_unitario' => '1.00',
    ]);
    $cotizacion->aplicarTotales($totales);
    $cotizacion->saveQuietly();

    return $cotizacion;
}

beforeEach(function () {
    config([
        'services.facturapi.llave' => 'sk_test_prueba',
        'services.facturapi.emisor' => ['rfc' => 'EKU9003173C9', 'razon_social' => 'ESCUELA KEMPER URGATE', 'regimen_fiscal' => '601', 'codigo_postal' => '26015'],
    ]);

    $this->user = User::factory()->create();
    $this->cliente = Cliente::factory()->for($this->user)->create([
        'razon_social' => 'PAPELERIA LA PLUMA SA DE CV',
        'rfc' => 'PPL010101AB1',
        'telefono' => '+524491112233',
        'correo' => 'compras@pluma.mx',
    ]);
    $this->articulo = articuloFacturable($this->user, ['nombre' => 'Sello automático', 'modelo' => 'P-20']);
    $this->cotizacion = cotizacionDelMostrador($this->user, $this->cliente, $this->articulo);
    $this->mostrador = fn () => $this->actingAs($this->user)->withSession([CandadoMostrador::SESION => true]);
});

describe('candado y barra', function () {
    it('con la marca, las pantallas de consulta responden y llevan la barra', function () {
        $factura = Factura::factory()->for($this->cliente)->conLinea()->create();

        foreach ([
            route('mostrador.inicio'),
            route('mostrador.cotizaciones'),
            route('mostrador.cotizaciones.ver', $this->cotizacion),
            route('mostrador.facturas'),
            route('mostrador.facturas.ver', $factura),
            route('mostrador.catalogo'),
            route('mostrador.catalogo.ver', $this->articulo),
        ] as $url) {
            ($this->mostrador)()->get($url)->assertOk()->assertSee('mostrador-secciones', false);
        }
    });

    it('la barra marca la sección actual', function () {
        $cotizaciones = 'href="'.route('mostrador.cotizaciones').'"';
        $facturas = 'href="'.route('mostrador.facturas').'"';
        $catalogo = 'href="'.route('mostrador.catalogo').'"';

        $detalle = ($this->mostrador)()->get(route('mostrador.cotizaciones.ver', $this->cotizacion))
            ->assertSeeInOrder([$cotizaciones, 'aria-current="page"', $facturas], false);
        expect(substr_count($detalle->getContent(), 'aria-current="page"'))->toBe(1);

        $lista = ($this->mostrador)()->get(route('mostrador.catalogo'))
            ->assertSeeInOrder([$catalogo, 'aria-current="page"'], false);
        expect(substr_count($lista->getContent(), 'aria-current="page"'))->toBe(1);
    });

    it('no hay barra en las capturas, el cobro, facturar ni el pago', function () {
        Cuenta::factory()->for($this->user)->create();

        foreach ([
            route('mostrador.factura'),
            route('mostrador.cotizacion'),
            route('mostrador.venta'),
            route('mostrador.cotizaciones.facturar', $this->cotizacion),
            route('mostrador.cotizaciones.pago', $this->cotizacion),
        ] as $url) {
            ($this->mostrador)()->get($url)->assertOk()->assertDontSee('mostrador-secciones', false);
        }
    });

    it('con la marca, facturar y pagar pasan; las pantallas de escritorio siguen cerradas', function () {
        ($this->mostrador)()->post(route('cotizaciones.timbrar', $this->cotizacion), ['origen' => 'mostrador'])
            ->assertSessionHasErrors('uso_cfdi');
        ($this->mostrador)()->post(route('cotizaciones.pagos.store', $this->cotizacion), ['origen' => 'mostrador'])
            ->assertSessionHasErrorsIn('pago', 'tipo');

        foreach ([route('cotizaciones.show', $this->cotizacion), route('facturas.index'), route('articulos.edit', $this->articulo)] as $url) {
            ($this->mostrador)()->get($url)->assertRedirect(route('mostrador.inicio'));
        }
    });
});

describe('listas', function () {
    it('sin texto muestra los últimos 30 días, del más reciente al más viejo', function () {
        $vieja = cotizacionDelMostrador($this->user, $this->cliente, $this->articulo);
        $vieja->forceFill(['created_at' => now()->subDays(40)])->saveQuietly();
        $ayer = cotizacionDelMostrador($this->user, $this->cliente, $this->articulo);
        $ayer->forceFill(['created_at' => now()->subDay()])->saveQuietly();
        $ajena = Cotizacion::factory()->conLinea()->create();

        ($this->mostrador)()->get(route('mostrador.cotizaciones'))
            ->assertOk()
            ->assertSeeInOrder([$this->cotizacion->folio_formateado, $ayer->folio_formateado])
            ->assertDontSee(route('mostrador.cotizaciones.ver', $vieja))
            ->assertDontSee(route('mostrador.cotizaciones.ver', $ajena))
            ->assertSee('Últimos 30 días');
    });

    it('el buscador de cotizaciones alcanza cualquier fecha por folio, razón social o RFC', function () {
        $otro = Cliente::factory()->for($this->user)->create(['razon_social' => 'FERRETERIA EL CLAVO', 'rfc' => 'FEC020202CD2']);
        $vieja = cotizacionDelMostrador($this->user, $otro, $this->articulo);
        $vieja->forceFill(['created_at' => now()->subDays(120)])->saveQuietly();
        $verVieja = route('mostrador.cotizaciones.ver', $vieja);
        $verActual = route('mostrador.cotizaciones.ver', $this->cotizacion);

        foreach (['clavo', 'FEC020202', $vieja->folio_formateado] as $texto) {
            ($this->mostrador)()->get(route('mostrador.tarjetas.cotizaciones', ['q' => $texto]))
                ->assertSee($verVieja)
                ->assertDontSee($verActual);
        }
    });

    it('el buscador de facturas encuentra por folio, folio fiscal, razón social, RFC y UUID', function () {
        $timbrada = Factura::factory()->for($this->cliente)->timbrada()->conLinea()->create();
        $timbrada->forceFill(['created_at' => now()->subDays(90)])->saveQuietly();
        $otro = Cliente::factory()->for($this->user)->create(['razon_social' => 'FERRETERIA EL CLAVO', 'rfc' => 'FEC020202CD2']);
        $otra = Factura::factory()->for($otro)->conLinea()->create();
        $ver = route('mostrador.facturas.ver', $timbrada);

        foreach (['pluma', 'PPL010101', $timbrada->folio_formateado, $timbrada->folioFiscal(), substr($timbrada->uuid_fiscal, 0, 13)] as $texto) {
            ($this->mostrador)()->get(route('mostrador.tarjetas.facturas', ['q' => $texto]))
                ->assertSee($ver)
                ->assertDontSee(route('mostrador.facturas.ver', $otra));
        }

        ($this->mostrador)()->get(route('mostrador.facturas'))->assertDontSee($ver);
    });

    it('pagina con data-siguiente y vuelve a pintar las páginas ya cargadas', function () {
        foreach (range(1, 45) as $i) {
            cotizacionDelMostrador($this->user, $this->cliente, $this->articulo);
        }

        ($this->mostrador)()->get(route('mostrador.tarjetas.cotizaciones'))
            ->assertSee('data-siguiente="'.e(route('mostrador.tarjetas.cotizaciones', ['page' => 2])).'"', false);

        ($this->mostrador)()->get(route('mostrador.tarjetas.cotizaciones', ['page' => 3]))
            ->assertDontSee('data-siguiente', false);

        // 46 cotizaciones: dos páginas juntas (40) y el marcador de la tercera, hacia las tarjetas.
        $respuesta = ($this->mostrador)()->get(route('mostrador.cotizaciones', ['paginas' => 2]))->assertOk();

        expect(substr_count($respuesta->getContent(), 'mostrador-ficha-documento'))->toBe(40);
        $respuesta->assertSee('data-siguiente="'.e(route('mostrador.tarjetas.cotizaciones', ['page' => 3])).'"', false);
    });

    it('el catálogo va en el orden de la lista de artículos: por id, de menor a mayor', function () {
        $zeta = articuloFacturable($this->user, ['nombre' => 'Zeta tinta']);
        $alfa = articuloFacturable($this->user, ['nombre' => 'Alfa cojín']);

        ($this->mostrador)()->get(route('mostrador.catalogo'))
            ->assertSeeInOrder(['Sello automático', 'Zeta tinta', 'Alfa cojín']);

        ($this->mostrador)()->get(route('mostrador.tarjetas.catalogo', ['q' => 'a']))
            ->assertSeeInOrder([route('mostrador.catalogo.ver', $zeta), route('mostrador.catalogo.ver', $alfa)]);
    });

    it('el catálogo lleva el precio con IVA y nunca costo, utilidad ni precio distribuidor', function () {
        $ajeno = articuloFacturable(User::factory()->create(), ['nombre' => 'Artículo ajeno']);
        $borrado = articuloFacturable($this->user, ['nombre' => 'Artículo borrado']);
        $borrado->delete();

        $con = '$'.number_format($this->articulo->precio_unitario_con_iva, 2);

        foreach ([route('mostrador.catalogo'), route('mostrador.catalogo.ver', $this->articulo)] as $url) {
            ($this->mostrador)()->get($url)
                ->assertOk()
                ->assertSee($con)
                ->assertDontSee('$'.number_format((float) $this->articulo->costo_con_descuento, 2))
                ->assertDontSee('$'.number_format($this->articulo->precio_distribuidor_con_iva, 2))
                ->assertDontSee('$'.number_format((float) $this->articulo->precio_unitario_sin_iva, 2))
                ->assertDontSee('Artículo ajeno')
                ->assertDontSee('Artículo borrado');
        }

        ($this->mostrador)()->get(route('mostrador.catalogo.ver', $this->articulo))
            ->assertSee('Sello automático — Modelo P-20 — '.$con)
            ->assertSee('data-compartir-ficha', false)
            ->assertDontSee(route('articulos.edit', $this->articulo));

        ($this->mostrador)()->get(route('mostrador.tarjetas.catalogo', ['q' => 'P-20']))->assertSee('Sello automático');
        ($this->mostrador)()->get(route('mostrador.catalogo.ver', $ajeno))->assertNotFound();
        ($this->mostrador)()->get("/mostrador/catalogo/{$borrado->id}")->assertNotFound();
    });
});

describe('detalle de cotización', function () {
    it('muestra cliente, RFC, renglones, totales, pagos y los envíos, sin editar ni eliminar', function () {
        $cuenta = Cuenta::factory()->for($this->user)->create();
        $this->cotizacion->pagos()->create(['tipo' => TipoPago::Anticipo, 'fecha_pago' => today(), 'cuenta_id' => $cuenta->id, 'monto' => '100.00']);

        ($this->mostrador)()->get(route('mostrador.cotizaciones.ver', $this->cotizacion))
            ->assertOk()
            ->assertSee('PAPELERIA LA PLUMA SA DE CV')
            ->assertSee('PPL010101AB1')
            ->assertSee('Sello automático')
            ->assertSee('$'.number_format((float) $this->cotizacion->total, 2))
            ->assertSee('Saldo pendiente')
            ->assertSee('$'.number_format((float) $this->cotizacion->total - 100, 2))
            ->assertSee(route('cotizaciones.marcar-enviada', $this->cotizacion))
            ->assertSee('compras@pluma.mx')
            ->assertSee(route('mostrador.cotizaciones.facturar', $this->cotizacion))
            ->assertSee(route('mostrador.cotizaciones.pago', $this->cotizacion))
            ->assertDontSee(route('cotizaciones.edit', $this->cotizacion))
            ->assertDontSee(route('cotizaciones.duplicar', $this->cotizacion));
    });

    it('una cotización ajena responde 404', function () {
        ($this->mostrador)()->get(route('mostrador.cotizaciones.ver', Cotizacion::factory()->conLinea()->create()))->assertNotFound();
        ($this->mostrador)()->get(route('mostrador.cotizaciones.facturar', Cotizacion::factory()->conLinea()->create()))->assertNotFound();
        ($this->mostrador)()->get(route('mostrador.cotizaciones.pago', Cotizacion::factory()->conLinea()->create()))->assertNotFound();
    });

    it('el botón Facturar sigue la regla del escritorio', function (Closure $preparar, string $espera, ?string $texto) {
        $preparar($this);

        $respuesta = ($this->mostrador)()->get(route('mostrador.cotizaciones.ver', $this->cotizacion))->assertOk();
        $facturar = route('mostrador.cotizaciones.facturar', $this->cotizacion);

        match ($espera) {
            'facturar' => $respuesta->assertSee($facturar),
            'ver' => $respuesta->assertDontSee($facturar)->assertSee('Ver su factura'),
            'apagado' => $respuesta->assertDontSee($facturar)->assertSee('data-motivo-facturar', false),
        };

        if ($texto !== null) {
            $respuesta->assertSee($texto);
        }
    })->with([
        'facturable' => [fn () => null, 'facturar', null],
        'factura pendiente' => [fn ($prueba) => Factura::factory()->for($prueba->cliente)->enEstado(EstadoFactura::Pendiente)->create(['cotizacion_id' => $prueba->cotizacion->id]), 'ver', 'quedó sin timbrar'],
        'factura timbrada' => [fn ($prueba) => Factura::factory()->for($prueba->cliente)->timbrada()->create(['cotizacion_id' => $prueba->cotizacion->id]), 'apagado', 'Ya facturada'],
        'factura cancelada' => [fn ($prueba) => Factura::factory()->for($prueba->cliente)->enEstado(EstadoFactura::Cancelada)->create(['cotizacion_id' => $prueba->cotizacion->id]), 'facturar', null],
        'en borrador' => [fn ($prueba) => $prueba->cotizacion->forceFill(['estado' => EstadoCotizacion::Borrador])->saveQuietly(), 'apagado', 'envíala primero'],
        'con línea libre' => [fn ($prueba) => $prueba->cotizacion->lineas()->update(['articulo_id' => null]), 'apagado', 'no vienen del catálogo'],
    ]);

    it('facturar una no facturable regresa al detalle con el motivo', function () {
        $this->cotizacion->forceFill(['estado' => EstadoCotizacion::Borrador])->saveQuietly();

        ($this->mostrador)()->get(route('mostrador.cotizaciones.facturar', $this->cotizacion))
            ->assertRedirect(route('mostrador.cotizaciones.ver', $this->cotizacion))
            ->assertSessionHas('error');
    });
});

describe('facturar desde el mostrador', function () {
    it('pinta los cinco pasos con la cotización a la vista y sin opción elegida', function () {
        ($this->mostrador)()->get(route('mostrador.cotizaciones.facturar', $this->cotizacion))
            ->assertOk()
            ->assertSee('Paso 1 de 5 · Uso de CFDI')
            ->assertSee('Facturando la cotización '.$this->cotizacion->folio_formateado.' — PAPELERIA LA PLUMA SA DE CV')
            ->assertSee('action="'.route('cotizaciones.timbrar', $this->cotizacion).'"', false)
            ->assertDontSee('mostrador-opcion-elegida', false)
            ->assertDontSee('name="lineas', false);
    });

    it('timbra la cotización tal como está y termina en el detalle de la factura', function () {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado())]);
        $otro = articuloFacturable($this->user);

        ($this->mostrador)()->post(route('cotizaciones.timbrar', $this->cotizacion), [
            'uso_cfdi' => 'G03',
            'forma_pago' => '01',
            'metodo_pago' => 'PUE',
            'origen' => 'mostrador',
            // Lo que llegue aquí se ignora: los renglones salen de la cotización.
            'lineas' => [['articulo_id' => $otro->id, 'cantidad' => 99, 'precio_unitario' => '1.00', 'tasa_iva' => '16', 'descripcion' => 'X']],
        ])->assertRedirect(route('mostrador.facturas.ver', Factura::sole()));

        $factura = Factura::sole()->load('lineas');

        expect($factura->cotizacion_id)->toBe($this->cotizacion->id)
            ->and($factura->estado)->toBe(EstadoFactura::Timbrada)
            ->and($factura->lineas->sole()->articulo_id)->toBe($this->articulo->id)
            ->and((string) $factura->lineas->sole()->cantidad)->toContain('3')
            ->and($factura->total)->toBe($this->cotizacion->total);

        ($this->mostrador)()->get(route('mostrador.cotizaciones.ver', $this->cotizacion))
            ->assertSee('Ya facturada en '.$factura->folioVisible())
            ->assertSee(route('mostrador.facturas.ver', $factura));
    });

    it('con una factura vigente regresa a ella en el mostrador y no crea otra', function () {
        $vigente = Factura::factory()->for($this->cliente)->enEstado(EstadoFactura::Pendiente)->create(['cotizacion_id' => $this->cotizacion->id]);

        ($this->mostrador)()->post(route('cotizaciones.timbrar', $this->cotizacion), ['uso_cfdi' => 'G03', 'forma_pago' => '01', 'metodo_pago' => 'PUE', 'origen' => 'mostrador'])
            ->assertRedirect(route('mostrador.facturas.ver', $vigente));

        expect(Factura::count())->toBe(1);
    });
});

describe('pago desde el mostrador', function () {
    beforeEach(function () {
        $this->banco = Cuenta::factory()->for($this->user)->create(['nombre' => 'Banco', 'tipo' => TipoCuenta::Banco]);
        $this->caja = Cuenta::factory()->for($this->user)->create(['nombre' => 'Caja', 'tipo' => TipoCuenta::Efectivo]);
    });

    it('trae el saldo escrito, la fecha de hoy y la caja preseleccionada', function () {
        ($this->mostrador)()->get(route('mostrador.cotizaciones.pago', $this->cotizacion))
            ->assertOk()
            ->assertSee('value="'.$this->cotizacion->saldoPendiente().'"', false)
            ->assertSee('value="'.now(config('app.zona_negocio'))->toDateString().'"', false)
            ->assertSee('<option value="'.$this->caja->id.'" selected', false)
            ->assertSee('name="tipo" value="pago_total"', false);
    });

    it('con un anticipo registrado el monto no se captura: se registra el saldo', function () {
        $this->cotizacion->pagos()->create(['tipo' => TipoPago::Anticipo, 'fecha_pago' => today(), 'cuenta_id' => $this->caja->id, 'monto' => '100.00']);

        ($this->mostrador)()->get(route('mostrador.cotizaciones.pago', $this->cotizacion))
            ->assertSee('Ya tiene un anticipo')
            ->assertSee('name="tipo" value="saldo"', false)
            ->assertDontSee('name="monto"', false);
    });

    it('si el pago crea la venta pide nombre y teléfono, ya llenos con los del cliente', function () {
        $this->articulo->catalogo->update(['requiere_produccion' => true]);

        ($this->mostrador)()->get(route('mostrador.cotizaciones.pago', $this->cotizacion))
            ->assertSee('se creará la venta')
            ->assertSee('name="cliente_telefono"', false)
            ->assertSee('value="4491112233"', false);
    });

    it('registra el pago y regresa al detalle; un error regresa a la pantalla de pago', function () {
        $pago = route('mostrador.cotizaciones.pago', $this->cotizacion);

        ($this->mostrador)()->from($pago)->post(route('cotizaciones.pagos.store', $this->cotizacion), [
            'origen' => 'mostrador', 'tipo' => 'anticipo', 'monto' => '0', 'fecha_pago' => now(config('app.zona_negocio'))->toDateString(), 'cuenta_id' => $this->caja->id,
        ])->assertRedirect($pago)->assertSessionHasErrorsIn('pago', 'monto');

        ($this->mostrador)()->post(route('cotizaciones.pagos.store', $this->cotizacion), [
            'origen' => 'mostrador', 'tipo' => 'pago_total', 'fecha_pago' => now(config('app.zona_negocio'))->toDateString(), 'cuenta_id' => $this->caja->id,
        ])->assertRedirect(route('mostrador.cotizaciones.ver', $this->cotizacion))->assertSessionHas('exito');

        expect($this->cotizacion->fresh()->estado)->toBe(EstadoCotizacion::Pagada)
            ->and($this->caja->fresh()->saldo_actual)->toBe($this->cotizacion->total);

        ($this->mostrador)()->get(route('mostrador.cotizaciones.ver', $this->cotizacion))
            ->assertDontSee(route('mostrador.cotizaciones.pago', $this->cotizacion))
            ->assertSee('ya está pagada por completo');
    });

    it('un pago que crea la venta no ofrece enlaces del escritorio', function () {
        $this->articulo->catalogo->update(['requiere_produccion' => true]);

        ($this->mostrador)()->post(route('cotizaciones.pagos.store', $this->cotizacion), [
            'origen' => 'mostrador', 'tipo' => 'pago_total', 'fecha_pago' => now(config('app.zona_negocio'))->toDateString(), 'cuenta_id' => $this->caja->id,
            'cliente_nombre' => 'Ana', 'cliente_telefono' => '4497654321',
        ])->assertRedirect(route('mostrador.cotizaciones.ver', $this->cotizacion))
            ->assertSessionHas('exito_enlaces', []);

        expect(Pedido::count())->toBe(1);
    });
});

describe('detalle de factura', function () {
    it('timbrada: UUID, WhatsApp, correo y la línea del XML; sin cancelar', function () {
        $factura = Factura::factory()->for($this->cliente)->timbrada()->conLinea()->create();

        ($this->mostrador)()->get(route('mostrador.facturas.ver', $factura))
            ->assertOk()
            ->assertSee($factura->uuid_fiscal)
            ->assertSee('PPL010101AB1')
            ->assertSee(route('facturas.pdf', $factura))
            ->assertSee('Por WhatsApp va el PDF; el XML se manda por correo.')
            ->assertSee('compras@pluma.mx')
            ->assertDontSee(route('facturas.cancelar', $factura))
            ->assertDontSee(route('facturas.complemento-pago', $factura));
    });

    it('cancelada: lo dice con su motivo y no ofrece envíos', function () {
        $factura = Factura::factory()->for($this->cliente)->timbrada()->conLinea()->create([
            'estado' => EstadoFactura::Cancelada,
            'motivo_cancelacion' => MotivoCancelacion::NoSeLlevoACabo,
        ]);

        ($this->mostrador)()->get(route('mostrador.facturas.ver', $factura))
            ->assertSee('Factura cancelada')
            ->assertSee(MotivoCancelacion::NoSeLlevoACabo->descripcion())
            ->assertDontSee('data-compartir-pdf', false)
            ->assertDontSee(route('facturas.enviar', $factura));
    });

    it('una factura ajena responde 404', function () {
        ($this->mostrador)()->get(route('mostrador.facturas.ver', Factura::factory()->conLinea()->create()))->assertNotFound();
    });
});
