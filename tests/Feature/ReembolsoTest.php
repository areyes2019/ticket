<?php

use App\Enums\DecisionFacturaReembolso;
use App\Enums\EstadoCancelacion;
use App\Enums\EstadoCotizacion;
use App\Enums\EstadoFactura;
use App\Enums\EstadoOrdenTrabajo;
use App\Enums\EstadoPedido;
use App\Enums\MotivoCancelacion;
use App\Enums\MotivoMovimientoInventario;
use App\Enums\TipoMovimiento;
use App\Exceptions\SaldoNegativoException;
use App\Models\Articulo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\Existencia;
use App\Models\Factura;
use App\Models\Movimiento;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\Reembolso;
use App\Models\User;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use App\Services\Inventario\RegistradorInventario;
use App\Services\Tesoreria\RegistradorMovimientos;
use Illuminate\Support\Facades\Http;

/**
 * Cotización enviada con una línea del artículo: cantidad × $100 al 16%.
 */
function cotizacionParaReembolso(User $user, Cliente $cliente, Articulo $articulo, int $cantidad): Cotizacion
{
    $cotizacion = Cotizacion::factory()->for($cliente)->enEstado(EstadoCotizacion::Enviada)->create(['user_id' => $user->id]);
    $linea = ['cantidad' => $cantidad, 'precio_unitario' => '100.00', 'tasa_iva' => '16', 'descuento_tipo' => null, 'descuento_valor' => null];
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
    $this->user = User::factory()->create();
    $this->caja = Cuenta::factory()->for($this->user)->create(['nombre' => 'Caja']);
    $this->banco = Cuenta::factory()->for($this->user)->create(['nombre' => 'Banco']);
    $this->sello = articuloFacturable($this->user, ['modelo' => 'S-4912']);

    // Venta de mostrador: 2 sellos + 1 línea libre = $300 + IVA = $348, pagada en dos cuentas.
    // Las 5 piezas entran con un ajuste (con historial), para que la auditoría cuadre.
    $this->venderEnMostrador = function (int $cantidad = 2): Pedido {
        app(RegistradorInventario::class)->ajustar($this->sello, 5, MotivoMovimientoInventario::EntradaInicial, null);
        $this->actingAs($this->user)->post('/pedidos', datosPedido([lineaPedido($this->sello, $cantidad), lineaPedido(null)]));
        $pedido = Pedido::latest('id')->first();
        pagarVenta($this, $pedido, '200.00', $this->caja);
        pagarVenta($this, $pedido, CalculadoraTotalesDocumento::pesos(CalculadoraTotalesDocumento::centavos($pedido->total) - 20000), $this->banco);

        return $pedido->fresh();
    };

    $this->reembolsar = fn (Pedido|Cotizacion $documento, array $datos = []) => $this->actingAs($this->user)->post(
        $documento instanceof Pedido ? "/pedidos/{$documento->id}/reembolso" : "/cotizaciones/{$documento->id}/reembolso",
        ['motivo' => 'El cliente ya no lo quiere', ...$datos],
    );

    $this->facturaTimbrada = fn (Pedido $pedido) => Factura::factory()->timbrada()
        ->for(Cliente::factory()->for($this->user))
        ->create(['pedido_id' => $pedido->id, 'total' => $pedido->total]);
});

/**
 * Pago de una venta de mostrador, por la ruta real (su ingreso en Tesorería).
 */
function pagarVenta(object $prueba, Pedido $pedido, string $monto, Cuenta $cuenta): void
{
    $prueba->actingAs($prueba->user)->post("/pedidos/{$pedido->id}/pagos", [
        'cuenta_id' => $cuenta->id,
        'fecha_pago' => today('America/Mexico_City')->toDateString(),
        'monto' => $monto,
    ])->assertSessionHasNoErrors();
}

describe('acceso', function () {
    it('pide sesión y una venta ajena responde 404', function () {
        $pedido = Pedido::factory()->for($this->user)->conLinea()->create();

        $this->post("/pedidos/{$pedido->id}/reembolso", ['motivo' => 'x'])->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->post("/pedidos/{$pedido->id}/reembolso", ['motivo' => 'x'])->assertNotFound();

        expect(Reembolso::count())->toBe(0);
    });
});

describe('la venta de mostrador', function () {
    it('devuelve cada pago por su cuenta, regresa los artículos y deja la venta reembolsada', function () {
        $pedido = ($this->venderEnMostrador)();

        ($this->reembolsar)($pedido)
            ->assertRedirect(route('pedidos.show', $pedido))
            ->assertSessionHas('exito', 'Venta PED-0001 reembolsada. Salieron $348.00 de Tesorería y sus artículos regresaron a existencias.');

        $egresos = Movimiento::where('tipo', TipoMovimiento::Egreso)->orderBy('id')->get();
        expect($egresos)->toHaveCount(2)
            ->and($egresos->pluck('monto')->all())->toBe(['-200.00', '-148.00'])
            ->and($egresos->pluck('cuenta_id')->all())->toBe([$this->caja->id, $this->banco->id])
            ->and($egresos->pluck('concepto')->unique()->all())->toBe(['Reembolso de Venta PED-0001'])
            ->and(Movimiento::where('tipo', TipoMovimiento::Ingreso)->count())->toBe(2)
            ->and($this->caja->fresh()->saldo_actual)->toBe('0.00')
            ->and($this->banco->fresh()->saldo_actual)->toBe('0.00');

        expect($pedido->fresh()->estado)->toBe(EstadoPedido::Reembolsado)
            ->and(Existencia::where('articulo_id', $this->sello->id)->value('existencia'))->toBe(5)
            ->and(MovimientoInventario::latest('id')->first()->motivo)->toBe(MotivoMovimientoInventario::ReembolsoVenta);

        expect(Reembolso::sole())
            ->monto->toBe('348.00')
            ->motivo->toBe('El cliente ya no lo quiere')
            ->factura_decision->toBeNull();
    });

    it('una venta editada regresa solo lo que sacó al final', function () {
        marcarExistencia($this->sello, 5);
        $this->actingAs($this->user)->post('/pedidos', datosPedido([lineaPedido($this->sello, 2)]));
        $pedido = Pedido::sole();
        $this->actingAs($this->user)->put("/pedidos/{$pedido->id}", datosPedido([lineaPedido($this->sello, 3)]))->assertSessionHasNoErrors();
        pagarVenta($this, $pedido, '100.00', $this->caja);

        ($this->reembolsar)($pedido)->assertSessionHas('exito');

        expect(Existencia::where('articulo_id', $this->sello->id)->value('existencia'))->toBe(5)
            ->and(MovimientoInventario::where('motivo', MotivoMovimientoInventario::ReembolsoVenta)->sole()->cantidad)->toBe(3);
    });

    it('sin artículos que hayan salido no regresa nada y lo dice el mensaje', function () {
        $pedido = Pedido::factory()->for($this->user)->conLinea()->create();
        pagarVenta($this, $pedido, '116.00', $this->caja);

        ($this->reembolsar)($pedido)->assertSessionHas('exito', 'Venta PED-0001 reembolsada. Salieron $116.00 de Tesorería.');

        expect(MovimientoInventario::count())->toBe(0);
    });
});

describe('la venta que nació de una cotización', function () {
    it('devuelve los pagos de la cotización, cancela la orden y reembolsa las dos', function () {
        $cliente = Cliente::factory()->for($this->user)->create();
        $this->sello->catalogo->update(['requiere_produccion' => true]);
        // Sin existencia: la venta deja faltante de 2 y el reembolso lo salda primero.
        marcarExistencia($this->sello, 0);
        $cotizacion = cotizacionParaReembolso($this->user, $cliente, $this->sello, 2);

        $this->actingAs($this->user)->post("/cotizaciones/{$cotizacion->id}/pagos", [
            'tipo' => 'pago_total',
            'cuenta_id' => $this->caja->id,
            'fecha_pago' => today('America/Mexico_City')->toDateString(),
            'cliente_nombre' => 'Ana López',
            'cliente_telefono' => '449 765 4321',
        ])->assertSessionHasNoErrors();
        $venta = Pedido::sole();
        expect(Existencia::where('articulo_id', $this->sello->id)->first())->faltante_pendiente->toBe(2);

        ($this->reembolsar)($venta)->assertSessionHas('exito');

        expect(Movimiento::where('tipo', TipoMovimiento::Egreso)->sole())
            ->monto->toBe('-232.00')
            ->cuenta_id->toBe($this->caja->id)
            ->and($venta->fresh()->estado)->toBe(EstadoPedido::Reembolsado)
            ->and($cotizacion->fresh()->estado)->toBe(EstadoCotizacion::Reembolsada)
            ->and($venta->ordenTrabajo->fresh()->estado)->toBe(EstadoOrdenTrabajo::Cancelada)
            ->and(Existencia::where('articulo_id', $this->sello->id)->first())
            ->existencia->toBe(0)
            ->faltante_pendiente->toBe(0);

        $this->actingAs($this->user)->get('/dashboard')->assertViewHas('ordenesTrabajo', fn ($ordenes) => $ordenes->isEmpty());
    });

    it('la cotización con venta no se reembolsa desde la cotización', function () {
        $cliente = Cliente::factory()->for($this->user)->create();
        $cotizacion = cotizacionParaReembolso($this->user, $cliente, $this->sello, 1);
        $venta = Pedido::factory()->for($this->user)->create(['cotizacion_id' => $cotizacion->id, 'cobro_en_cotizacion' => true]);
        $cotizacion->forceFill(['estado' => EstadoCotizacion::Aceptada])->save();
        $cotizacion->pagos()->forceCreate(['tipo' => 'pago_total', 'cuenta_id' => $this->caja->id, 'fecha_pago' => today(), 'monto' => '116.00']);

        ($this->reembolsar)($cotizacion)->assertSessionHas('error', "Para reembolsar, entra a la venta {$venta->folio_formateado}.");

        expect(Reembolso::count())->toBe(0);
    });
});

describe('la cotización sin venta', function () {
    beforeEach(function () {
        $cliente = Cliente::factory()->for($this->user)->distribuidor()->create();
        marcarExistencia($this->sello, 5);
        $this->cotizacion = cotizacionParaReembolso($this->user, $cliente, $this->sello, 2);
        $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/pagos", [
            'tipo' => 'pago_total',
            'cuenta_id' => $this->caja->id,
            'fecha_pago' => today('America/Mexico_City')->toDateString(),
        ])->assertSessionHasNoErrors();
    });

    it('entregada, regresa sus artículos', function () {
        $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/entregar")->assertSessionHas('exito');

        ($this->reembolsar)($this->cotizacion)
            ->assertRedirect(route('cotizaciones.show', $this->cotizacion))
            ->assertSessionHas('exito', 'Cotización COT-0001 reembolsada. Salieron $232.00 de Tesorería y sus artículos regresaron a existencias.');

        expect($this->cotizacion->fresh()->estado)->toBe(EstadoCotizacion::Reembolsada)
            ->and(Existencia::where('articulo_id', $this->sello->id)->value('existencia'))->toBe(5)
            ->and(Movimiento::where('tipo', TipoMovimiento::Egreso)->sole()->concepto)->toBe('Reembolso de Cotización COT-0001');
    });

    it('sin entregar, el dinero sale y no regresa nada', function () {
        ($this->reembolsar)($this->cotizacion)->assertSessionHas('exito', 'Cotización COT-0001 reembolsada. Salieron $232.00 de Tesorería.');

        expect(MovimientoInventario::count())->toBe(0)
            ->and($this->caja->fresh()->saldo_actual)->toBe('0.00');
    });

    it('ya reembolsada no recibe pagos ni los elimina', function () {
        ($this->reembolsar)($this->cotizacion);
        $pago = $this->cotizacion->pagos()->sole();

        $this->actingAs($this->user)->delete("/cotizaciones/{$this->cotizacion->id}/pagos/{$pago->id}")
            ->assertSessionHas('error', 'Los pagos de una cotización reembolsada no se eliminan: el reembolso ya los devolvió.');

        expect($this->cotizacion->pagos()->count())->toBe(1);
    });
});

describe('la factura', function () {
    it('timbrada y "Sí": pide la cancelación al SAT y reembolsa', function () {
        Http::fake(['www.facturapi.io/v2/invoices/*' => Http::response(['status' => 'canceled', 'cancellation_status' => 'accepted'])]);
        $pedido = ($this->venderEnMostrador)();
        $factura = ($this->facturaTimbrada)($pedido);

        ($this->reembolsar)($pedido, ['cancelar_factura' => 'si', 'motivo_cancelacion' => '03'])
            ->assertSessionHas('exito', fn (string $mensaje) => str_ends_with($mensaje, "Se pidió la cancelación de la factura {$factura->folioVisible()}: Cancelada."));

        expect($factura->fresh())
            ->estado->toBe(EstadoFactura::Cancelada)
            ->motivo_cancelacion->toBe(MotivoCancelacion::NoSeLlevoACabo)
            ->and(Reembolso::sole())
            ->factura_id->toBe($factura->id)
            ->factura_decision->toBe(DecisionFacturaReembolso::Cancelar)
            ->motivo_cancelacion->toBe(MotivoCancelacion::NoSeLlevoACabo)
            ->and($pedido->fresh()->estado)->toBe(EstadoPedido::Reembolsado);

        $this->actingAs($this->user)->get("/facturas/{$factura->id}")->assertSee('Cancelada por el reembolso de la');
    });

    it('timbrada y "Sí" con la cancelación pendiente: reembolsa sin esperar', function () {
        Http::fake(['www.facturapi.io/v2/invoices/*' => Http::response(['status' => 'valid', 'cancellation_status' => 'pending'])]);
        $pedido = ($this->venderEnMostrador)();
        $factura = ($this->facturaTimbrada)($pedido);

        ($this->reembolsar)($pedido, ['cancelar_factura' => 'si', 'motivo_cancelacion' => '02'])
            ->assertSessionHas('exito', fn (string $mensaje) => str_ends_with($mensaje, 'Pendiente de que el cliente la acepte.'));

        expect($factura->fresh())
            ->estado->toBe(EstadoFactura::Timbrada)
            ->estado_cancelacion->toBe(EstadoCancelacion::Pendiente)
            ->and($pedido->fresh()->estado)->toBe(EstadoPedido::Reembolsado);
    });

    it('timbrada y "Sí" con error de facturapi.io: no reembolsa nada', function () {
        Http::fake(['www.facturapi.io/v2/invoices/*' => Http::response(['message' => 'Error interno'], 500)]);
        $pedido = ($this->venderEnMostrador)();
        $factura = ($this->facturaTimbrada)($pedido);

        ($this->reembolsar)($pedido, ['cancelar_factura' => 'si', 'motivo_cancelacion' => '03'])
            ->assertSessionHas('error', fn (string $mensaje) => str_starts_with($mensaje, "No se pudo pedir la cancelación de la factura {$factura->folioVisible()}:")
                && str_ends_with($mensaje, 'No se reembolsó nada; inténtalo de nuevo.'));

        expect(Reembolso::count())->toBe(0)
            ->and(Movimiento::where('tipo', TipoMovimiento::Egreso)->count())->toBe(0)
            ->and(Existencia::where('articulo_id', $this->sello->id)->value('existencia'))->toBe(3)
            ->and($pedido->fresh()->estado)->toBe(EstadoPedido::Pagado);
    });

    it('timbrada y "No": la deja vigente y la venta lo avisa', function () {
        Http::fake();
        $pedido = ($this->venderEnMostrador)();
        $factura = ($this->facturaTimbrada)($pedido);

        ($this->reembolsar)($pedido, ['cancelar_factura' => 'no'])
            ->assertSessionHas('exito', fn (string $mensaje) => str_ends_with($mensaje, "La factura {$factura->folioVisible()} quedó vigente."));

        Http::assertNothingSent();
        expect($factura->fresh()->estado)->toBe(EstadoFactura::Timbrada)
            ->and(Reembolso::sole()->factura_decision)->toBe(DecisionFacturaReembolso::Conservar);

        $this->actingAs($this->user)->get("/pedidos/{$pedido->id}")->assertSee("Reembolsada con factura vigente {$factura->folioVisible()}.");
    });

    it('en borrador o pendiente se descarta y guarda su folio', function (EstadoFactura $estado) {
        $pedido = ($this->venderEnMostrador)();
        $factura = Factura::factory()->enEstado($estado)->for(Cliente::factory()->for($this->user))->create(['pedido_id' => $pedido->id]);

        ($this->reembolsar)($pedido)
            ->assertSessionHas('exito', fn (string $mensaje) => str_ends_with($mensaje, "Se descartó la factura {$factura->folio_formateado}."));

        expect(Factura::find($factura->id))->toBeNull()
            ->and(Reembolso::sole())
            ->factura_id->toBeNull()
            ->factura_folio->toBe($factura->folio_formateado)
            ->factura_decision->toBe(DecisionFacturaReembolso::Descartar);
    })->with([EstadoFactura::Borrador, EstadoFactura::Pendiente]);

    it('rechazada después: la venta ofrece volver a cancelar y regresa a ella', function () {
        Http::fake(['www.facturapi.io/v2/invoices/*' => Http::response(['status' => 'valid', 'cancellation_status' => 'pending'])]);
        $pedido = ($this->venderEnMostrador)();
        $factura = ($this->facturaTimbrada)($pedido);
        ($this->reembolsar)($pedido, ['cancelar_factura' => 'si', 'motivo_cancelacion' => '03']);
        $factura->forceFill(['estado_cancelacion' => EstadoCancelacion::Rechazada])->save();

        $this->actingAs($this->user)->get("/pedidos/{$pedido->id}")
            ->assertSee("La factura {$factura->folioVisible()} sigue vigente: la cancelación fue rechazada.")
            ->assertSee('Volver a cancelar factura');

        Http::fake(['www.facturapi.io/v2/invoices/*' => Http::response(['status' => 'canceled', 'cancellation_status' => 'accepted'])]);
        $this->actingAs($this->user)->post("/facturas/{$factura->id}/cancelar", ['motivo_cancelacion' => '03', 'regresar_a' => "/pedidos/{$pedido->id}"])
            ->assertRedirect(route('pedidos.show', $pedido));
    });

    it('el regreso después de cancelar solo acepta rutas internas', function () {
        Http::fake(['www.facturapi.io/v2/invoices/*' => Http::response(['status' => 'canceled', 'cancellation_status' => 'accepted'])]);
        $factura = Factura::factory()->timbrada()->for(Cliente::factory()->for($this->user))->create();

        $this->actingAs($this->user)->post("/facturas/{$factura->id}/cancelar", ['motivo_cancelacion' => '02', 'regresar_a' => '//sitio-ajeno.example/robo'])
            ->assertRedirect(route('facturas.show', $factura));
    });
});

describe('la revisión antes de llamar al SAT', function () {
    it('con una cuenta sin saldo no llama a facturapi.io ni mueve nada', function () {
        Http::fake();
        $pedido = ($this->venderEnMostrador)();
        ($this->facturaTimbrada)($pedido);
        app(RegistradorMovimientos::class)->registrar($this->banco, TipoMovimiento::Egreso, '100.00', today()->toDateString(), 'Retiro');

        ($this->reembolsar)($pedido, ['cancelar_factura' => 'si', 'motivo_cancelacion' => '03'])
            ->assertSessionHas('error', 'La cuenta Banco no tiene saldo suficiente para devolver $148.00.');

        Http::assertNothingSent();
        expect(Reembolso::count())->toBe(0)
            ->and($pedido->fresh()->estado)->toBe(EstadoPedido::Pagado);
    });

    it('con una cuenta inactiva tampoco', function () {
        $pedido = ($this->venderEnMostrador)();
        $this->caja->update(['activa' => false]);

        ($this->reembolsar)($pedido)->assertSessionHas('error', 'La cuenta Caja está inactiva. Actívala para hacer el reembolso.');

        expect(Reembolso::count())->toBe(0);
    });
});

describe('rechazos', function () {
    it('sin pagos no hay nada que reembolsar', function () {
        $pedido = Pedido::factory()->for($this->user)->conLinea()->create();

        ($this->reembolsar)($pedido)->assertSessionHas('error', 'La venta no tiene pagos: no hay nada que reembolsar.');
    });

    it('pide el motivo del reembolso', function () {
        $pedido = ($this->venderEnMostrador)();

        ($this->reembolsar)($pedido, ['motivo' => ''])->assertSessionHasErrorsIn('reembolso', ['motivo']);

        expect(Reembolso::count())->toBe(0);
    });

    it('con factura timbrada pide decidir, y con "Sí" un motivo SAT que no sea 01', function () {
        Http::fake();
        $pedido = ($this->venderEnMostrador)();
        ($this->facturaTimbrada)($pedido);

        ($this->reembolsar)($pedido)->assertSessionHasErrorsIn('reembolso', ['cancelar_factura' => 'Elige si también se cancela la factura.']);
        ($this->reembolsar)($pedido, ['cancelar_factura' => 'si'])->assertSessionHasErrorsIn('reembolso', ['motivo_cancelacion' => 'Elige el motivo de cancelación del SAT.']);
        ($this->reembolsar)($pedido, ['cancelar_factura' => 'si', 'motivo_cancelacion' => '01'])
            ->assertSessionHasErrorsIn('reembolso', ['motivo_cancelacion' => 'Elige un motivo 02, 03 o 04. El 01 se usa desde Facturación.']);

        Http::assertNothingSent();
        expect(Reembolso::count())->toBe(0);
    });
});

describe('todo o nada y doble reembolso', function () {
    it('si falla un paso dentro de la transacción no queda nada', function () {
        $pedido = ($this->venderEnMostrador)();
        // El segundo egreso falla después de que el primero ya se escribió.
        Movimiento::created(function (Movimiento $movimiento) {
            if ($movimiento->tipo === TipoMovimiento::Egreso && $movimiento->cuenta_id === $this->banco->id) {
                throw new SaldoNegativoException($this->banco);
            }
        });

        ($this->reembolsar)($pedido)->assertSessionHas('error', 'La cuenta Banco no tiene saldo suficiente para devolver $148.00.');

        expect(Reembolso::count())->toBe(0)
            ->and(Movimiento::where('tipo', TipoMovimiento::Egreso)->count())->toBe(0)
            ->and(MovimientoInventario::where('motivo', MotivoMovimientoInventario::ReembolsoVenta)->count())->toBe(0)
            ->and($this->caja->fresh()->saldo_actual)->toBe('200.00')
            ->and($pedido->fresh()->estado)->toBe(EstadoPedido::Pagado);
    });

    it('el segundo envío no mueve nada y el inventario cuadra', function () {
        $pedido = ($this->venderEnMostrador)();

        ($this->reembolsar)($pedido)->assertSessionHas('exito');
        ($this->reembolsar)($pedido)->assertSessionHas('error', 'Esta venta ya se reembolsó.');

        expect(Reembolso::count())->toBe(1)
            ->and(Movimiento::where('tipo', TipoMovimiento::Egreso)->count())->toBe(2);

        $this->artisan('inventario:auditar')->assertSuccessful();
    });
});

describe('la venta reembolsada', function () {
    it('queda congelada: sin pagos, edición, entrega ni autofactura', function () {
        $pedido = ($this->venderEnMostrador)();
        ($this->reembolsar)($pedido);
        $pedido->refresh();

        pagarVentaRechazada($this, $pedido);
        $this->actingAs($this->user)->delete("/pedidos/{$pedido->id}/pagos/{$pedido->pagos()->first()->id}")
            ->assertSessionHas('error', 'Los pagos de una venta reembolsada no se eliminan: el reembolso ya los devolvió.');
        $this->actingAs($this->user)->get("/pedidos/{$pedido->id}/editar")->assertForbidden();
        $this->actingAs($this->user)->post("/pedidos/{$pedido->id}/entregar")->assertSessionHas('error');
        $this->get("/autofactura/{$pedido->autofactura_token}")->assertSee('Esta venta se reembolsó.');

        expect($pedido->fresh()->estado)->toBe(EstadoPedido::Reembolsado)
            ->and($pedido->pagos()->count())->toBe(2);
    });

    it('en Tesorería sus ingresos ya no muestran utilidad', function () {
        $pedido = ($this->venderEnMostrador)();
        ($this->reembolsar)($pedido);

        $this->actingAs($this->user)->get('/tesoreria/movimientos')->assertSee('data-utilidad-reembolsada', false);
    });
});

function pagarVentaRechazada(object $prueba, Pedido $pedido): void
{
    $prueba->actingAs($prueba->user)->post("/pedidos/{$pedido->id}/pagos", [
        'cuenta_id' => $prueba->caja->id,
        'fecha_pago' => today('America/Mexico_City')->toDateString(),
        'monto' => '1.00',
    ])->assertSessionHasErrorsIn('pago', ['monto' => 'La venta se reembolsó: ya no recibe pagos.']);
}
