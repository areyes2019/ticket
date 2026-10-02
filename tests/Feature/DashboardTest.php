<?php

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Factura;
use App\Models\User;
use App\Support\Demo\BandejaCorreoDemo;

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
            ->assertSee('Selecciona una cotización o una factura');
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

it('manda al login a quien abre el dashboard sin sesión', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
});
