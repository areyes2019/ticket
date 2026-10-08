<?php

use App\Enums\EstadoCotizacion;
use App\Enums\EstadoFactura;
use App\Enums\FormaPago;
use App\Enums\TipoCuenta;
use App\Enums\UsoCfdi;
use App\Http\Middleware\CandadoMostrador;
use App\Models\Articulo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\Pedido;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Línea de factura o cotización como la arma mostrador.js.
 *
 * @return array<string, mixed>
 */
function lineaMostrador(Articulo $articulo, int $cantidad = 1): array
{
    return [
        'articulo_id' => (string) $articulo->id,
        'cantidad' => (string) $cantidad,
        'descripcion' => $articulo->nombre,
        'modelo' => $articulo->modelo,
        'precio_unitario' => '100.00',
        'descuento_tipo' => '',
        'descuento_valor' => '',
        'tasa_iva' => '16',
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->cliente = Cliente::factory()->for($this->user)->create(['correo' => 'cliente@ejemplo.mx']);
    $this->articulo = articuloFacturable($this->user);
    marcarExistencia($this->articulo, 10);
});

describe('candado', function () {
    it('pide sesión y regresa al mostrador después de entrar', function () {
        $this->get('/mostrador')->assertRedirect('/login');

        $this->post('/login', ['email' => $this->user->email, 'password' => 'password'])
            ->assertRedirect(route('mostrador.inicio'));
    });

    it('/mostrador marca la sesión y muestra los tres accesos', function () {
        $this->actingAs($this->user)->get('/mostrador')
            ->assertOk()
            ->assertSessionHas(CandadoMostrador::SESION, true)
            ->assertSee('Generar factura')
            ->assertSee('Generar cotización')
            ->assertSee('Venta al público')
            ->assertSee('Cerrar sesión')
            ->assertDontSee('menu-apps');
    });

    it('sin la marca, el sistema completo responde como siempre', function (string $url) {
        $this->actingAs($this->user)->get($url)->assertOk();
    })->with(['/dashboard', '/articulos', '/tesoreria/cuentas']);

    it('con la marca, cualquier otra pantalla lleva a los tres accesos', function (string $url) {
        $this->actingAs($this->user)->withSession([CandadoMostrador::SESION => true])
            ->get($url)
            ->assertRedirect(route('mostrador.inicio'));
    })->with(['/dashboard', '/articulos', '/tesoreria/cuentas', '/configuracion', '/pedidos']);

    it('con la marca, una acción fuera de la lista responde 403', function () {
        $this->actingAs($this->user)->withSession([CandadoMostrador::SESION => true])
            ->post(route('tesoreria.cuentas.store'), ['nombre' => 'Caja', 'tipo' => 'efectivo', 'saldo_inicial' => '0'])
            ->assertForbidden();

        $this->actingAs($this->user)->withSession([CandadoMostrador::SESION => true])
            ->getJson(route('articulos.sugerencias', ['q' => 'a']))
            ->assertForbidden();
    });

    it('con la marca, las rutas que usan las capturas siguen respondiendo', function () {
        $pedido = Pedido::factory()->for($this->user)->conLinea()->create();
        $sesion = $this->actingAs($this->user)->withSession([CandadoMostrador::SESION => true]);

        $sesion->get(route('mostrador.factura'))->assertOk();
        $sesion->get(route('mostrador.tarjetas.clientes'))->assertOk();
        $sesion->get(route('pedidos.ticket', $pedido))->assertOk();
        $sesion->getJson(route('pedidos.cliente-por-telefono', ['telefono' => '4491234567']))->assertOk();
    });

    it('cerrar sesión borra la marca', function () {
        $this->actingAs($this->user)->withSession([CandadoMostrador::SESION => true])
            ->post(route('logout'))
            ->assertSessionMissing(CandadoMostrador::SESION);
    });
});

describe('tarjetas', function () {
    it('lista solo los clientes del usuario y busca por razón social, nombre comercial o RFC', function () {
        Cliente::factory()->for($this->user)->create(['razon_social' => 'PAPELERIA ROJAS', 'nombre_comercial' => 'La Roja', 'rfc' => 'PRO010101AB1']);
        Cliente::factory()->create(['razon_social' => 'PAPELERIA AJENA']);

        $sesion = $this->actingAs($this->user);

        $sesion->get(route('mostrador.tarjetas.clientes'))->assertOk()->assertSee($this->cliente->razon_social)->assertSee('PAPELERIA ROJAS')->assertDontSee('PAPELERIA AJENA');
        $sesion->get(route('mostrador.tarjetas.clientes', ['q' => 'la roja']))->assertSee('PAPELERIA ROJAS')->assertDontSee($this->cliente->razon_social);
        $sesion->get(route('mostrador.tarjetas.clientes', ['q' => 'pro 0101']))->assertSee('PAPELERIA ROJAS');
        $sesion->get(route('mostrador.tarjetas.clientes', ['q' => 'papeleria']))->assertDontSee('PAPELERIA AJENA');
    });

    it('marca la página siguiente salvo en la última', function () {
        Cliente::factory()->for($this->user)->count(25)->create();

        $this->actingAs($this->user)->get(route('mostrador.tarjetas.clientes'))->assertSee('data-siguiente', false);
        $this->actingAs($this->user)->get(route('mostrador.tarjetas.clientes', ['page' => 2]))->assertDontSee('data-siguiente', false);
    });

    it('busca artículos por nombre, modelo o proveedor y lleva los precios de las sugerencias', function () {
        $otro = articuloFacturable($this->user, ['nombre' => 'Fechador', 'modelo' => 'F-200']);
        $otro->proveedor()->associate(Proveedor::factory()->for($this->user)->create(['nombre_comercial' => 'Trodat México']))->save();
        Articulo::factory()->create(['nombre' => 'Artículo ajeno']);

        $sesion = $this->actingAs($this->user);
        $sugerencia = $sesion->getJson(route('articulos.sugerencias', ['q' => 'F-200']))->json(0);

        $sesion->get(route('mostrador.tarjetas.articulos'))->assertSee('Fechador')->assertSee($this->articulo->nombre)->assertDontSee('Artículo ajeno');
        $sesion->get(route('mostrador.tarjetas.articulos', ['q' => 'f-200']))->assertSee('Fechador')->assertDontSee($this->articulo->nombre);
        $sesion->get(route('mostrador.tarjetas.articulos', ['q' => 'trodat']))->assertSee('Fechador');

        $html = $sesion->get(route('mostrador.tarjetas.articulos', ['q' => 'f-200']))->getContent();
        preg_match('/data-articulo="([^"]+)"/', $html, $coincidencia);
        $tarjeta = json_decode(html_entity_decode($coincidencia[1]), true);

        expect($tarjeta)->toMatchArray([
            'id' => $sugerencia['id'],
            'precio_unitario' => $sugerencia['precio_unitario'],
            'precio_distribuidor' => $sugerencia['precio_distribuidor'],
            'tasa_iva' => $sugerencia['tasa_iva'],
        ]);
    });
});

describe('capturas', function () {
    it('pinta las tres capturas', function (string $ruta, string $texto) {
        $this->actingAs($this->user)->get(route($ruta))->assertOk()->assertSee($texto);
    })->with([
        ['mostrador.venta', 'Guardar y cobrar'],
        ['mostrador.cotizacion', 'Guardar cotización'],
        ['mostrador.factura', 'Timbrar'],
    ]);

    it('la factura pinta todos los usos y formas de pago sin ninguno elegido', function () {
        $html = $this->actingAs($this->user)->get(route('mostrador.factura'))->getContent();

        foreach (UsoCfdi::deFactura() as $uso) {
            expect($html)->toContain('data-opcion="'.$uso->value.'"');
        }

        foreach (FormaPago::cases() as $forma) {
            expect($html)->toContain('data-opcion="'.$forma->value.'"');
        }

        expect($html)->not->toContain('mostrador-opcion-elegida')
            ->toContain('name="uso_cfdi" value=""');
    });

    it('arranca con el cliente que llega del alta e ignora uno ajeno', function () {
        $ajeno = Cliente::factory()->create(['razon_social' => 'CLIENTE AJENO SA']);

        $this->actingAs($this->user)->get(route('mostrador.cotizacion', ['cliente' => $this->cliente->id]))
            ->assertSee('data-cliente-inicial', false);
        $this->actingAs($this->user)->get(route('mostrador.cotizacion', ['cliente' => $ajeno->id]))
            ->assertDontSee('data-cliente-inicial', false)
            ->assertDontSee('CLIENTE AJENO SA');
    });

    it('el alta de cliente pinta la constancia y el formulario corto', function () {
        $this->actingAs($this->user)->get(route('mostrador.cliente-nuevo', 'factura'))
            ->assertOk()
            ->assertSee('data-constancia', false)
            ->assertSee('Usar este cliente')
            ->assertSee('name="flujo" value="factura"', false);

        $this->actingAs($this->user)->get('/mostrador/venta/cliente-nuevo')->assertNotFound();
    });
});

describe('regreso con origen=mostrador', function () {
    it('el alta de cliente regresa a la captura con el cliente elegido', function () {
        $respuesta = $this->actingAs($this->user)->post(route('clientes.store'), [
            'rfc' => 'XAXX010101000',
            'razon_social' => 'PUBLICO EN GENERAL',
            'regimen_fiscal' => '616',
            'codigo_postal_fiscal' => '20000',
            'es_distribuidor' => '0',
            'descuento_permanente' => '0',
            'origen' => 'mostrador',
            'flujo' => 'cotizacion',
        ]);

        $cliente = Cliente::where('rfc', 'XAXX010101000')->sole();
        $respuesta->assertRedirect(route('mostrador.cotizacion', ['cliente' => $cliente->id]));
    });

    it('un flujo inválido conserva la redirección de siempre', function () {
        $this->actingAs($this->user)->post(route('clientes.store'), [
            'rfc' => 'XAXX010101000',
            'razon_social' => 'PUBLICO EN GENERAL',
            'regimen_fiscal' => '616',
            'codigo_postal_fiscal' => '20000',
            'origen' => 'mostrador',
            'flujo' => 'venta',
        ])->assertRedirect(route('clientes.index'));
    });

    it('la venta pasa al cobro, el cobro al ticket, y un cobro fallido no duplica la venta', function () {
        $caja = Cuenta::factory()->for($this->user)->create(['tipo' => TipoCuenta::Efectivo, 'nombre' => 'Caja']);

        $this->actingAs($this->user)->post(route('pedidos.store'), datosPedido([lineaPedido($this->articulo)], ['origen' => 'mostrador']))
            ->assertRedirect(route('mostrador.venta.cobro', Pedido::sole()));

        $pedido = Pedido::sole();

        $this->actingAs($this->user)->from(route('mostrador.venta.cobro', $pedido))
            ->post(route('pedidos.pagos.store', $pedido), ['origen' => 'mostrador', 'cuenta_id' => $caja->id, 'fecha_pago' => now(config('app.zona_negocio'))->toDateString(), 'monto' => '9999.00'])
            ->assertRedirect(route('mostrador.venta.cobro', $pedido))
            ->assertSessionHasErrors('monto', null, 'pago');

        $this->actingAs($this->user)->get(route('mostrador.venta.cobro', $pedido))->assertSee('No. '.$pedido->numero_ticket);

        $this->actingAs($this->user)
            ->post(route('pedidos.pagos.store', $pedido), ['origen' => 'mostrador', 'cuenta_id' => $caja->id, 'fecha_pago' => now(config('app.zona_negocio'))->toDateString(), 'monto' => $pedido->total])
            ->assertRedirect(route('mostrador.venta.listo', $pedido));

        expect(Pedido::count())->toBe(1)->and($pedido->fresh()->saldoPendiente())->toBe('0.00');

        $this->actingAs($this->user)->get(route('mostrador.venta.listo', $pedido))
            ->assertOk()
            ->assertSee('Compartir por WhatsApp')
            ->assertSee(route('pedidos.ticket', $pedido));
    });

    it('el cobro preselecciona la cuenta de efectivo activa más antigua', function () {
        Cuenta::factory()->for($this->user)->create(['nombre' => 'Banco']);
        $caja = Cuenta::factory()->for($this->user)->create(['tipo' => TipoCuenta::Efectivo, 'nombre' => 'Caja']);
        Cuenta::factory()->for($this->user)->create(['tipo' => TipoCuenta::Efectivo, 'nombre' => 'Caja chica']);
        $pedido = Pedido::factory()->for($this->user)->conLinea()->create();

        $this->actingAs($this->user)->get(route('mostrador.venta.cobro', $pedido))
            ->assertOk()
            ->assertSee('<option value="'.$caja->id.'" selected', false)
            ->assertDontSee('Elige la cuenta…');
    });

    it('sin cuenta de efectivo el cobro no preselecciona nada', function () {
        Cuenta::factory()->for($this->user)->create(['nombre' => 'Banco']);
        $pedido = Pedido::factory()->for($this->user)->conLinea()->create();

        $this->actingAs($this->user)->get(route('mostrador.venta.cobro', $pedido))->assertSee('Elige la cuenta…');
    });

    it('la cotización lleva a su pantalla de envío y el correo regresa a ella', function () {
        Mail::fake();

        $this->actingAs($this->user)->post(route('cotizaciones.store'), [
            'cliente_id' => $this->cliente->id,
            'lineas' => [lineaMostrador($this->articulo, 2)],
            'origen' => 'mostrador',
        ])->assertRedirect(route('mostrador.cotizacion.listo', Cotizacion::sole()));

        $cotizacion = Cotizacion::sole();

        $this->actingAs($this->user)->get(route('mostrador.cotizacion.listo', $cotizacion))
            ->assertOk()
            ->assertSee($cotizacion->folio_formateado)
            ->assertSee(route('cotizaciones.marcar-enviada', $cotizacion))
            ->assertSee('cliente@ejemplo.mx');

        $this->actingAs($this->user)->post(route('cotizaciones.enviar', $cotizacion), ['destinatarios_texto' => 'cliente@ejemplo.mx', 'origen' => 'mostrador'])
            ->assertRedirect(route('mostrador.cotizacion.listo', $cotizacion));

        expect($cotizacion->fresh()->estado)->toBe(EstadoCotizacion::Enviada);
    });

    describe('factura', function () {
        beforeEach(function () {
            config([
                'services.facturapi.llave' => 'sk_test_prueba',
                'services.facturapi.emisor' => ['rfc' => 'EKU9003173C9', 'razon_social' => 'ESCUELA KEMPER URGATE', 'regimen_fiscal' => '601', 'codigo_postal' => '26015'],
            ]);
        });

        function timbrarDesdeMostrador($prueba): Factura
        {
            $prueba->actingAs($prueba->user)->post(route('facturas.store'), [
                'cliente_id' => $prueba->cliente->id,
                'uso_cfdi' => 'G03',
                'forma_pago' => '01',
                'metodo_pago' => 'PUE',
                'lineas' => [lineaMostrador($prueba->articulo)],
                'origen' => 'mostrador',
            ])->assertRedirect(route('mostrador.factura.listo', Factura::sole()));

            return Factura::sole();
        }

        it('timbrada: folio fiscal, WhatsApp con el PDF y correo', function () {
            Http::fake(['www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado())]);

            $factura = timbrarDesdeMostrador($this);

            expect($factura->estado)->toBe(EstadoFactura::Timbrada);

            $this->actingAs($this->user)->get(route('mostrador.factura.listo', $factura))
                ->assertOk()
                ->assertSee($factura->folioFiscal())
                ->assertSee(route('facturas.pdf', $factura))
                ->assertSee('Enviar por correo')
                ->assertDontSee('Reintentar');
        });

        it('falla del PAC: muestra el motivo y reintenta desde el mostrador', function () {
            Http::fake(['www.facturapi.io/v2/invoices' => Http::sequence()
                ->push(['message' => 'Servicio no disponible'], 503)
                ->push(respuestaTimbrado())]);

            $factura = timbrarDesdeMostrador($this);

            $this->actingAs($this->user)->get(route('mostrador.factura.listo', $factura))
                ->assertSee('Reintentar')
                ->assertSee(route('facturas.timbrar', $factura));

            $this->actingAs($this->user)->post(route('facturas.timbrar', $factura), ['origen' => 'mostrador'])
                ->assertRedirect(route('mostrador.factura.listo', $factura));

            expect($factura->fresh()->estado)->toBe(EstadoFactura::Timbrada);
        });

        it('rechazada por datos: motivo y corrección en la computadora, sin reintentar', function () {
            Http::fake(['www.facturapi.io/v2/invoices' => Http::response(['message' => 'El RFC del receptor no está en la lista de RFC inscritos.'], 400)]);

            $factura = timbrarDesdeMostrador($this);

            $this->actingAs($this->user)->get(route('mostrador.factura.listo', $factura))
                ->assertSee('El RFC del receptor no está en la lista de RFC inscritos.')
                ->assertSee('corrige los datos desde la computadora')
                ->assertDontSee('Reintentar');
        });
    });
});

describe('resultados', function () {
    it('los resultados de documentos ajenos responden 404', function (string $ruta, Closure $documento) {
        $this->actingAs($this->user)->get(route($ruta, $documento()))->assertNotFound();
    })->with([
        ['mostrador.venta.cobro', fn () => Pedido::factory()->conLinea()->create()],
        ['mostrador.venta.listo', fn () => Pedido::factory()->conLinea()->create()],
        ['mostrador.cotizacion.listo', fn () => Cotizacion::factory()->conLinea()->create()],
        ['mostrador.factura.listo', fn () => Factura::factory()->conLinea()->create()],
    ]);
});

describe('instalación', function () {
    it('el manifest abre en el mostrador y sus iconos existen', function () {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        expect($manifest['start_url'])->toBe('/mostrador')
            ->and($manifest['display'])->toBe('standalone');

        foreach ($manifest['icons'] as $icono) {
            expect(file_exists(public_path(ltrim($icono['src'], '/'))))->toBeTrue();
        }
    });

    it('los layouts enlazan el manifest y el dashboard ofrece instalar', function () {
        $this->actingAs($this->user)->get('/dashboard')
            ->assertSee('manifest.webmanifest')
            ->assertSee('data-instalar-app', false);
    });
});
