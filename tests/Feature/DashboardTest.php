<?php

use App\Enums\EstadoOrdenTrabajo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\OrdenTrabajo;
use App\Models\Pedido;
use App\Models\User;
use App\Support\Demo\BandejaCorreoDemo;
use Illuminate\Support\Facades\DB;

it('muestra la bandeja de correo de demostración en el dashboard', function () {
    $correo = (new BandejaCorreoDemo)->correos()[0];

    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertDontSee('Bandeja de demostración')
        ->assertSee('Bandeja de entrada')
        ->assertSee($correo['asunto'])
        ->assertSee(asset('js/bandeja-correo.js'), false);
});

it('abre el correo desde un menú de aplicaciones con un sobre', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('aria-label="Aplicaciones"', false)
        ->assertSee('data-abrir-app="correo"', false)
        ->assertSee('bi-envelope', false)
        ->assertSee('data-app="correo"  hidden', false)
        ->assertSee(asset('js/dashboard-apps.js'), false);
});

it('llega con el correo abierto cuando el menú manda ?app=correo', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard?app=correo')
        ->assertOk()
        ->assertDontSee('data-app="correo"  hidden', false)
        ->assertSee('data-escritorio-inicio  hidden', false);
});

it('muestra el menú de aplicaciones fijo en las demás páginas', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('clientes.index'))
        ->assertOk()
        ->assertSee('class="con-menu-apps"', false)
        ->assertSee('aria-label="Aplicaciones"', false)
        ->assertSee(route('dashboard', ['app' => 'correo']), false)
        ->assertSeeInOrder(['Dashboard', 'Correo', 'Ventas', 'Compras', 'Inventario'])
        ->assertSee('menu-apps-seccion-activa', false)
        ->assertDontSee(asset('js/dashboard-apps.js'), false);
});

describe('inicio con cotizaciones y facturas', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->cliente = Cliente::factory()->create(['user_id' => $this->user->id, 'razon_social' => 'Papelería Luna']);
        $this->cotizacion = Cotizacion::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);
        $this->factura = Factura::factory()->for($this->cliente)->timbrada()->conLinea()->create(['user_id' => $this->user->id]);
    });

    it('muestra las dos listas y abre la cotización más reciente', function () {
        $ajena = Cotizacion::factory()->conLinea()->create();

        $this->actingAs($this->user)->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['Cotizaciones', $this->cotizacion->folio_formateado, 'Facturas', $this->factura->folioVisible()])
            ->assertSee(route('cotizaciones.vista-previa', $this->cotizacion), false)
            ->assertSee(route('facturas.vista-previa', $this->factura), false)
            ->assertDontSee(route('cotizaciones.vista-previa', $ajena), false)
            ->assertSee('data-vista-previa-de="'.$this->cotizacion->id.'" data-documento="cotizacion"', false)
            ->assertSee(asset('js/bandeja-documentos.js'), false);
    });

    it('abre la factura pedida en la URL e ignora una ajena', function () {
        $this->actingAs($this->user)->get('/dashboard?factura='.$this->factura->id)
            ->assertSee('data-vista-previa-de="'.$this->factura->id.'" data-documento="factura"', false);

        $ajena = Factura::factory()->timbrada()->conLinea()->create();

        $this->actingAs($this->user)->get('/dashboard?factura='.$ajena->id)
            ->assertSee('data-documento="cotizacion"', false)
            ->assertDontSee('data-vista-previa-de="'.$ajena->id.'" data-documento="factura"', false);
    });

    it('avisa de las listas vacías a quien no tiene documentos', function () {
        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertOk()
            ->assertSee('Sin cotizaciones')
            ->assertSee('Sin órdenes de trabajo')
            ->assertSee('Selecciona una cotización, una factura o una orden');
    });

    it('ofrece ver todas y una ventana para crear la cotización', function () {
        $this->actingAs($this->user)->get('/dashboard')
            ->assertSee('aria-label="Ver todas las cotizaciones"', false)
            ->assertSee('href="#dialogo-nueva-cotizacion"', false)
            ->assertSee('<dialog id="dialogo-nueva-cotizacion"', false)
            ->assertSee('action="'.route('cotizaciones.store').'"', false)
            ->assertSee('name="origen" value="dashboard"', false)
            ->assertDontSee('data-abrir-al-cargar', false)
            ->assertSee(asset('js/documento-lineas.js'), false);
    });

    it('crea la cotización desde la ventana y regresa al dashboard con ella abierta', function () {
        $respuesta = $this->actingAs($this->user)->post('/cotizaciones', [
            'origen' => 'dashboard',
            'cliente_id' => $this->cliente->id,
            'lineas' => [[
                'articulo_id' => '', 'cantidad' => '2', 'descripcion' => 'Sello personalizado', 'modelo' => '',
                'precio_unitario' => '100.00', 'descuento_tipo' => '', 'descuento_valor' => '', 'tasa_iva' => '16',
            ]],
        ]);

        $nueva = $this->user->cotizaciones()->latest('id')->first();

        $respuesta->assertRedirect(route('dashboard', ['cotizacion' => $nueva->id]));

        $this->get(route('dashboard', ['cotizacion' => $nueva->id]))
            ->assertSee('Cotización '.$nueva->folio_formateado.' creada.')
            ->assertSee('data-vista-previa-de="'.$nueva->id.'" data-documento="cotizacion"', false);
    });

    it('reabre la ventana con lo capturado si la cotización no es válida', function () {
        $this->actingAs($this->user)->from('/dashboard')->post('/cotizaciones', [
            'origen' => 'dashboard',
            'cliente_id' => '',
            'lineas' => [[
                'articulo_id' => '', 'cantidad' => '3', 'descripcion' => 'Tinta azul', 'modelo' => '',
                'precio_unitario' => '50.00', 'descuento_tipo' => '', 'descuento_valor' => '', 'tasa_iva' => '16',
            ]],
        ])->assertRedirect('/dashboard');

        $this->get('/dashboard')
            ->assertSee('data-abrir-al-cargar', false)
            ->assertSee('value="Tinta azul"', false);

        expect($this->user->cotizaciones()->count())->toBe(1);
    });

    it('devuelve la vista previa de una factura con su hoja y acciones', function () {
        $this->actingAs($this->user)->get(route('facturas.vista-previa', $this->factura))
            ->assertOk()
            ->assertSee('Factura '.$this->factura->folioVisible())
            ->assertSee('Papelería Luna')
            ->assertSee(route('facturas.pdf', [$this->factura, 'descargar' => 1]), false)
            ->assertSee(route('facturas.enviar', $this->factura), false)
            ->assertSee(route('facturas.show', $this->factura), false);
    });
});

describe('documentos en acordeón y órdenes de trabajo', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();

        // Una orden sin pasar por los pagos: lo que se prueba aquí es el dashboard.
        $this->ordenDe = function (User $user, string $cliente, string $estado = 'en_dibujo'): OrdenTrabajo {
            $pedido = Pedido::factory()->for($user)->conLinea()->create(['cliente_nombre' => $cliente]);
            $orden = new OrdenTrabajo;
            $orden->forceFill(['user_id' => $user->id, 'pedido_id' => $pedido->id, 'estado' => $estado])->save();

            return $orden;
        };
    });

    it('pone cotizaciones y facturas en un acordeón con Cotizaciones abierta', function () {
        $cliente = Cliente::factory()->create(['user_id' => $this->user->id]);
        Cotizacion::factory()->for($cliente)->conLinea()->create(['user_id' => $this->user->id]);
        $factura = Factura::factory()->for($cliente)->timbrada()->conLinea()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->get('/dashboard')
            ->assertOk()
            ->assertSee('data-seccion="cotizaciones" open>', false)
            ->assertSee('data-seccion="facturas" >', false)
            ->assertSee('aria-label="Ver todas las facturas"', false)
            ->assertSee(asset('js/dashboard-secciones.js'), false);

        $this->actingAs($this->user)->get('/dashboard?factura='.$factura->id)
            ->assertSee('data-seccion="facturas"  open >', false);
    });

    it('lista las órdenes propias de todos los estados con la hoja de producción', function () {
        $enDibujo = ($this->ordenDe)($this->user, 'Cliente en dibujo');
        $terminada = ($this->ordenDe)($this->user, 'Cliente terminado', 'terminado');
        ($this->ordenDe)(User::factory()->create(), 'Cliente ajeno');

        $this->actingAs($this->user)->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['Órdenes de trabajo', 'Cliente terminado', 'Cliente en dibujo'])
            ->assertSee($enDibujo->pedido->folio_formateado)
            ->assertSee('En dibujo')
            ->assertSee('Terminado')
            ->assertSee(route('pedidos.orden-trabajo.vista-previa', $terminada->pedido), false)
            ->assertSee('data-ot="'.$enDibujo->id.'"', false)
            ->assertSee(route('pedidos.produccion'), false)
            ->assertDontSee('Cliente ajeno');
    });

    it('saca de la lista las órdenes entregadas, pero ?ot= todavía las abre', function () {
        ($this->ordenDe)($this->user, 'Cliente terminado', 'terminado');
        $entregada = ($this->ordenDe)($this->user, 'Cliente entregado', 'entregado');

        $this->actingAs($this->user)->get('/dashboard')
            ->assertSee('Cliente terminado')
            ->assertDontSee('data-ot="'.$entregada->id.'"', false);

        $this->actingAs($this->user)->get('/dashboard?ot='.$entregada->id)
            ->assertSee('data-vista-previa-de="'.$entregada->id.'" data-documento="ot"', false)
            ->assertDontSee('data-ot="'.$entregada->id.'"', false);
    });

    it('entregar desde el visor regresa al dashboard sin la orden, que sale de la lista', function () {
        $orden = ($this->ordenDe)($this->user, 'Cliente por entregar', 'terminado');
        $cuenta = Cuenta::factory()->for($this->user)->create();

        $this->actingAs($this->user)->get('/dashboard?ot='.$orden->id)
            ->assertSee('Entregado')
            ->assertSee('name="origen" value="dashboard"', false);

        $this->actingAs($this->user)->post("/pedidos/{$orden->pedido_id}/entregar", ['origen' => 'dashboard', 'cuenta_id' => $cuenta->id])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('exito');

        expect($orden->fresh()->estado)->toBe(EstadoOrdenTrabajo::Entregado);
        $this->actingAs($this->user)->get('/dashboard')->assertDontSee('data-ot="'.$orden->id.'"', false);
    });

    it('marca las órdenes con líneas sin color', function () {
        ($this->ordenDe)($this->user, 'Cliente sin color');

        $this->actingAs($this->user)->get('/dashboard')->assertSee('Falta color');
    });

    it('abre la orden pedida en la URL e ignora una ajena', function () {
        $orden = ($this->ordenDe)($this->user, 'Cliente propio');
        $ajena = ($this->ordenDe)(User::factory()->create(), 'Cliente ajeno');
        $cliente = Cliente::factory()->create(['user_id' => $this->user->id]);
        Cotizacion::factory()->for($cliente)->conLinea()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->get('/dashboard?ot='.$orden->id)
            ->assertSee('data-vista-previa-de="'.$orden->id.'" data-documento="ot"', false);

        $this->actingAs($this->user)->get('/dashboard?ot='.$ajena->id)
            ->assertSee('data-documento="cotizacion"', false)
            ->assertDontSee('data-documento="ot"', false);
    });

    it('sin cotizaciones ni facturas abre la orden más reciente', function () {
        ($this->ordenDe)($this->user, 'Cliente anterior');
        $reciente = ($this->ordenDe)($this->user, 'Cliente reciente');

        $this->actingAs($this->user)->get('/dashboard')
            ->assertSee('data-vista-previa-de="'.$reciente->id.'" data-documento="ot"', false);
    });

    it('no hace una consulta por cada orden de la lista', function () {
        ($this->ordenDe)($this->user, 'Cliente 1');
        $this->actingAs($this->user)->get('/dashboard');

        DB::enableQueryLog();
        $this->get('/dashboard')->assertOk();
        $conUna = count(DB::getQueryLog());

        foreach (range(2, 6) as $i) {
            ($this->ordenDe)($this->user, "Cliente {$i}");
        }

        DB::flushQueryLog();
        $this->get('/dashboard')->assertOk();

        expect(count(DB::getQueryLog()))->toBe($conUna);
    });
});

it('manda al login a quien abre el dashboard sin sesión', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
});
