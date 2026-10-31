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

it('no muestra el enlace a Correo en el menú de aplicaciones', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('aria-label="Aplicaciones"', false)
        ->assertDontSee('data-abrir-app="correo"', false)
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
        ->assertDontSee(route('dashboard', ['app' => 'correo']), false)
        ->assertSeeInOrder(['Dashboard', 'Ventas', 'Compras', 'Inventario'])
        ->assertSee('menu-apps-seccion-activa', false)
        ->assertDontSee(asset('js/dashboard-apps.js'), false);
});

it('ya no muestra cotizaciones ni facturas en el inicio', function () {
    $user = User::factory()->create();
    $cliente = Cliente::factory()->create(['user_id' => $user->id]);
    $cotizacion = Cotizacion::factory()->for($cliente)->conLinea()->create(['user_id' => $user->id]);
    $factura = Factura::factory()->for($cliente)->timbrada()->conLinea()->create(['user_id' => $user->id]);

    $this->actingAs($user)->get('/dashboard?cotizacion='.$cotizacion->id.'&factura='.$factura->id)
        ->assertOk()
        ->assertDontSee('Buscar cotización')
        ->assertDontSee('Buscar factura')
        ->assertDontSee(route('cotizaciones.vista-previa', $cotizacion), false)
        ->assertDontSee(route('facturas.vista-previa', $factura), false)
        ->assertDontSee('dialogo-nueva-cotizacion', false)
        ->assertSee('Sin órdenes de trabajo')
        ->assertSee('Selecciona una orden')
        ->assertSee(asset('js/bandeja-documentos.js'), false);
});

describe('órdenes de trabajo', function () {
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

        $this->actingAs($this->user)->get('/dashboard?ot='.$orden->id)
            ->assertSee('data-vista-previa-de="'.$orden->id.'" data-documento="ot"', false);

        $this->actingAs($this->user)->get('/dashboard?ot='.$ajena->id)
            ->assertSee('data-vista-previa-de="'.$orden->id.'" data-documento="ot"', false)
            ->assertDontSee('data-vista-previa-de="'.$ajena->id.'"', false);
    });

    it('abre la orden más reciente', function () {
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
