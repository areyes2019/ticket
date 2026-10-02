<?php

use App\Enums\EstadoCotizacion;
use App\Enums\EstadoFactura;
use App\Enums\EstadoPedido;
use App\Enums\MotivoMovimientoInventario;
use App\Enums\TipoDescuento;
use App\Models\Articulo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\Existencia;
use App\Models\Factura;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\User;
use App\Services\Documentos\CalculadoraTotalesDocumento;

/**
 * Cotización con una línea de catálogo (3 piezas a $80, 10% de descuento) y una
 * libre, con descuento global de $5.
 */
function cotizacionParaAceptar(User $user, Cliente $cliente, Articulo $articulo, EstadoCotizacion $estado = EstadoCotizacion::Enviada): Cotizacion
{
    $cotizacion = Cotizacion::factory()->for($cliente)->enEstado($estado)
        ->create(['user_id' => $user->id, 'descuento_global_tipo' => TipoDescuento::Monto, 'descuento_global_valor' => '5.00']);

    $lineas = [
        ['articulo_id' => $articulo->id, 'cantidad' => 3, 'descripcion' => $articulo->nombre, 'modelo' => $articulo->modelo, 'precio_unitario' => '80.00', 'descuento_tipo' => 'porcentaje', 'descuento_valor' => '10.00', 'tasa_iva' => '16'],
        ['articulo_id' => null, 'cantidad' => 1, 'descripcion' => 'Diseño especial', 'modelo' => null, 'precio_unitario' => '50.00', 'descuento_tipo' => null, 'descuento_valor' => null, 'tasa_iva' => '16'],
    ];
    $totales = CalculadoraTotalesDocumento::calcular($lineas, 'monto', '5.00');

    foreach ($lineas as $i => $linea) {
        $cotizacion->lineas()->create([
            ...$linea,
            'orden' => $i + 1,
            'importe' => $totales['lineas'][$i]['importe'],
            'iva_importe' => $totales['lineas'][$i]['iva_importe'],
            'costo_unitario' => $linea['articulo_id'] === null ? null : '1.00',
        ]);
    }

    $cotizacion->aplicarTotales($totales);
    $cotizacion->saveQuietly();

    return $cotizacion;
}

/**
 * @return array<string, string>
 */
function datosAceptar(array $cambios = []): array
{
    return ['cliente_nombre' => 'Ana López', 'cliente_telefono' => '449 765 4321', 'cliente_correo' => 'ana@example.com', ...$cambios];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->cliente = Cliente::factory()->for($this->user)->create(['telefono' => '+524491112233']);
    $this->articulo = articuloFacturable($this->user);
    $this->cotizacion = cotizacionParaAceptar($this->user, $this->cliente, $this->articulo);
});

describe('acceso', function () {
    it('pide sesión', function () {
        $this->post("/cotizaciones/{$this->cotizacion->id}/aceptar", datosAceptar())->assertRedirect('/login');

        expect(Pedido::count())->toBe(0);
    });

    it('una cotización ajena responde 404', function () {
        $this->actingAs(User::factory()->create())
            ->post("/cotizaciones/{$this->cotizacion->id}/aceptar", datosAceptar())
            ->assertNotFound();

        expect(Pedido::count())->toBe(0);
    });
});

describe('aceptar', function () {
    it('crea la venta con el mismo cliente, líneas, descuentos y total, y deja la cotización aceptada', function (EstadoCotizacion $estado) {
        $this->cotizacion->forceFill(['estado' => $estado])->saveQuietly();

        $respuesta = $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/aceptar", datosAceptar());

        $venta = Pedido::sole();
        $respuesta->assertRedirect(route('pedidos.show', $venta))
            ->assertSessionHas('exito', "Cotización {$this->cotizacion->folio_formateado} aceptada. Se creó la venta PED-0001. Registra el pago para compartir el ticket.");

        expect($venta)
            ->estado->toBe(EstadoPedido::Pendiente)
            ->cotizacion_id->toBe($this->cotizacion->id)
            ->cliente_id->toBe($this->cliente->id)
            ->cliente_nombre->toBe('Ana López')
            ->cliente_telefono->toBe('+524497654321')
            ->cliente_correo->toBe('ana@example.com')
            ->descuento_global_tipo->toBe(TipoDescuento::Monto)
            ->descuento_global_valor->toBe('5.00')
            ->total->toBe($this->cotizacion->total)
            ->subtotal->toBe($this->cotizacion->subtotal)
            ->and($venta->pagos()->count())->toBe(0);

        $lineas = $venta->lineas;
        expect($lineas)->toHaveCount(2)
            ->and($lineas[0]->only(['articulo_id', 'cantidad', 'precio_unitario', 'descuento_valor', 'importe']))
            ->toBe(['articulo_id' => $this->articulo->id, 'cantidad' => 3, 'precio_unitario' => '80.00', 'descuento_valor' => '10.00', 'importe' => $this->cotizacion->lineas[0]->importe])
            // El costo es el del artículo al aceptar, no el que guardó la cotización.
            ->and($lineas[0]->costo_unitario)->toBe($this->articulo->fresh()->costo_con_descuento)
            ->and($lineas[1]->articulo_id)->toBeNull()
            ->and($lineas[1]->costo_unitario)->toBeNull();

        $cotizacion = $this->cotizacion->fresh();
        expect($cotizacion->estado)->toBe(EstadoCotizacion::Aceptada)
            ->and($cotizacion->aceptada_en)->not->toBeNull()
            ->and($cotizacion->venta->is($venta))->toBeTrue();
    })->with(['enviada' => EstadoCotizacion::Enviada, 'borrador' => EstadoCotizacion::Borrador]);

    it('el folio sigue la numeración de ventas, junto con las de mostrador', function () {
        marcarExistencia($this->articulo, 10);
        $this->actingAs($this->user)->post('/pedidos', datosPedido([lineaPedido(null)]));

        $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/aceptar", datosAceptar());

        expect(Pedido::orderBy('id')->pluck('folio')->all())->toBe([1, 2])
            ->and($this->user->fresh()->ultimo_folio_pedido)->toBe(2);
    });

    it('exige nombre y teléfono, y no toca el catálogo de clientes', function () {
        $this->actingAs($this->user)
            ->post("/cotizaciones/{$this->cotizacion->id}/aceptar", datosAceptar(['cliente_nombre' => '', 'cliente_telefono' => '123']))
            ->assertSessionHasErrorsIn('aceptar', ['cliente_nombre', 'cliente_telefono']);

        $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/aceptar", datosAceptar());

        expect($this->cliente->fresh()->telefono)->toBe('+524491112233')
            ->and(Pedido::count())->toBe(1);
    });

    it('no se acepta en otros estados, con pagos, facturada o ya aceptada', function (Closure $preparar, string $motivo) {
        $preparar($this);

        $this->actingAs($this->user)
            ->post("/cotizaciones/{$this->cotizacion->id}/aceptar", datosAceptar())
            ->assertSessionHasErrorsIn('aceptar', 'cotizacion');

        expect(session('errors')->getBag('aceptar')->first('cotizacion'))->toStartWith($motivo)
            ->and(Pedido::count())->toBe(0);
    })->with([
        'pagada' => [fn ($prueba) => $prueba->cotizacion->forceFill(['estado' => EstadoCotizacion::Pagada])->saveQuietly(), 'Solo se acepta una cotización en borrador o enviada.'],
        'entregada' => [fn ($prueba) => $prueba->cotizacion->forceFill(['estado' => EstadoCotizacion::ProductoEntregado])->saveQuietly(), 'Solo se acepta una cotización en borrador o enviada.'],
        'con pagos' => [fn ($prueba) => $prueba->cotizacion->pagos()->create(['tipo' => 'anticipo', 'fecha_pago' => today(), 'monto' => '10.00', 'cuenta_id' => Cuenta::factory()->for($prueba->user)->create()->id]), 'Esta cotización ya tiene pagos: se cobra y se entrega desde aquí.'],
        'facturada' => [fn ($prueba) => Factura::factory()->for($prueba->cliente)->create(['user_id' => $prueba->user->id, 'cotizacion_id' => $prueba->cotizacion->id]), 'Ya tiene la factura'],
    ]);

    it('aceptar dos veces crea una sola venta', function () {
        $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/aceptar", datosAceptar());

        $this->actingAs($this->user)
            ->post("/cotizaciones/{$this->cotizacion->id}/aceptar", datosAceptar())
            ->assertSessionHasErrorsIn('aceptar', ['cotizacion' => 'Ya se aceptó: su venta es PED-0001.']);

        expect(Pedido::count())->toBe(1);
    });
});

describe('inventario', function () {
    it('descuenta al aceptar sin bloquear: crea la fila que falta y deja faltante', function () {
        $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/aceptar", datosAceptar())
            ->assertSessionHasNoErrors();

        expect(Existencia::sole())->existencia->toBe(0)->faltante_pendiente->toBe(3)
            ->and(MovimientoInventario::sole())
            ->motivo->toBe(MotivoMovimientoInventario::VentaPedido)
            ->documentable_type->toBe('pedido');
    });

    it('con existencia insuficiente descuenta lo que hay y deja el resto como faltante', function () {
        marcarExistencia($this->articulo, 2);

        $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/aceptar", datosAceptar());

        expect(Existencia::sole())->existencia->toBe(0)->faltante_pendiente->toBe(1);
    });

    it('editar la venta de una cotización no se bloquea por existencia', function () {
        $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/aceptar", datosAceptar());
        $venta = Pedido::sole();
        $otro = articuloFacturable($this->user);

        $this->actingAs($this->user)->put("/pedidos/{$venta->id}", datosPedido([lineaPedido($this->articulo, 1), lineaPedido($otro, 2)]))
            ->assertSessionHasNoErrors();

        expect(Existencia::where('articulo_id', $otro->id)->sole()->faltante_pendiente)->toBe(2)
            ->and(Existencia::where('articulo_id', $this->articulo->id)->sole()->faltante_pendiente)->toBe(1);
    });

    it('la cotización aceptada no se entrega ni descuenta por su lado', function () {
        $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/aceptar", datosAceptar());

        $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/entregar")
            ->assertSessionHas('error');

        expect(MovimientoInventario::count())->toBe(1);
    });
});

describe('cotización aceptada', function () {
    beforeEach(function () {
        $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/aceptar", datosAceptar());
        $this->venta = Pedido::sole();
    });

    it('ya no se edita ni se elimina, y dice dónde corregir', function () {
        $this->actingAs($this->user)->get("/cotizaciones/{$this->cotizacion->id}/editar")
            ->assertForbidden()
            ->assertSee('Una cotización aceptada ya no se modifica: corrige la venta PED-0001.');

        $this->actingAs($this->user)->delete("/cotizaciones/{$this->cotizacion->id}")
            ->assertSessionHas('error', 'Una cotización aceptada ya no se modifica: corrige la venta PED-0001.');

        expect(Cotizacion::count())->toBe(1);
    });

    it('no recibe pagos', function () {
        $cuenta = Cuenta::factory()->for($this->user)->create();

        $this->actingAs($this->user)
            ->post("/cotizaciones/{$this->cotizacion->id}/pagos", ['tipo' => 'pago_total', 'cuenta_id' => $cuenta->id, 'fecha_pago' => today('America/Mexico_City')->toDateString()])
            ->assertSessionHasErrorsIn('pago');

        expect($this->cotizacion->pagos()->count())->toBe(0);
    });

    it('no caduca', function () {
        $this->travel(Cotizacion::DIAS_CADUCIDAD + 5)->days();

        $this->artisan('cotizaciones:purgar-vencidas')->assertSuccessful();

        expect(Cotizacion::count())->toBe(1);
    });

    it('el detalle enlaza la venta y ya no ofrece aceptar, editar ni cobrar', function () {
        $this->actingAs($this->user)->get("/cotizaciones/{$this->cotizacion->id}")
            ->assertOk()
            ->assertSee('Venta · PED-0001')
            ->assertSee(route('pedidos.show', $this->venta))
            ->assertDontSee('#dialogo-aceptar')
            ->assertDontSee(route('cotizaciones.edit', $this->cotizacion))
            ->assertDontSee('Pago total');
    });

    it('la venta cobra, sale pagada y abre el enlace de autofactura', function () {
        $cuenta = Cuenta::factory()->for($this->user)->create();

        $this->actingAs($this->user)
            ->post("/pedidos/{$this->venta->id}/pagos", ['cuenta_id' => $cuenta->id, 'fecha_pago' => today('America/Mexico_City')->toDateString(), 'monto' => $this->venta->total])
            ->assertSessionHasNoErrors();

        $venta = $this->venta->fresh();
        expect($venta->estado)->toBe(EstadoPedido::Pagado)
            ->and($venta->motivoAutofacturaNoDisponible())->toBeNull();

        $this->actingAs($this->user)->get("/pedidos/{$venta->id}")
            ->assertOk()
            ->assertSee('Venta PED-0001')
            ->assertSee('Origen: cotización')
            ->assertSee($this->cliente->rfc);
    });

    it('borrar la venta devuelve existencias y regresa la cotización a enviada', function () {
        $this->travel(3)->days();

        $this->actingAs($this->user)->delete("/pedidos/{$this->venta->id}")
            ->assertRedirect(route('pedidos.index'))
            ->assertSessionHas('exito', "Venta PED-0001 eliminada. Sus artículos regresaron a existencias. La cotización {$this->cotizacion->folio_formateado} volvió a Enviada.");

        $cotizacion = $this->cotizacion->fresh();
        expect(Pedido::count())->toBe(0)
            ->and($cotizacion->estado)->toBe(EstadoCotizacion::Enviada)
            ->and($cotizacion->aceptada_en)->toBeNull()
            ->and($cotizacion->updated_at->isToday())->toBeTrue()
            ->and($cotizacion->puedeAceptarse())->toBeTrue()
            ->and(Existencia::sole()->faltante_pendiente)->toBe(0);
    });
});

describe('una sola factura entre la cotización aceptada y su venta', function () {
    beforeEach(function () {
        $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/aceptar", datosAceptar());
        $this->venta = Pedido::sole();
        $cuenta = Cuenta::factory()->for($this->user)->create();
        $this->actingAs($this->user)->post("/pedidos/{$this->venta->id}/pagos", ['cuenta_id' => $cuenta->id, 'fecha_pago' => today('America/Mexico_City')->toDateString(), 'monto' => $this->venta->total]);
        $this->venta->refresh();
    });

    it('la aceptada se puede facturar mientras su venta no tenga factura', function () {
        // Su línea libre impide el CFDI desde la cotización; sin ella, sí se podría.
        $this->cotizacion->lineas()->whereNull('articulo_id')->delete();

        expect($this->cotizacion->fresh()->motivoNoFacturable())->toBeNull();
    });

    it('facturar la cotización cierra la autofactura de la venta', function () {
        Factura::factory()->for($this->cliente)->create(['user_id' => $this->user->id, 'cotizacion_id' => $this->cotizacion->id]);

        expect($this->venta->fresh()->motivoAutofacturaNoDisponible())->toBe('Esta venta ya se facturó.');

        $this->get('/autofactura/'.$this->venta->autofactura_token)->assertOk()->assertSee('Esta venta ya se facturó.');
    });

    it('la autofactura de la venta cierra la factura de la cotización, y cancelarla la libera', function () {
        $this->cotizacion->lineas()->whereNull('articulo_id')->delete();
        $factura = Factura::factory()->for($this->cliente)->create(['user_id' => $this->user->id, 'pedido_id' => $this->venta->id]);

        expect($this->cotizacion->fresh()->motivoNoFacturable())->toStartWith('Su venta PED-0001 ya se facturó en')
            ->and(Cotizacion::porFacturar()->count())->toBe(0);

        $factura->forceFill(['estado' => EstadoFactura::Cancelada])->save();

        expect($this->cotizacion->fresh()->motivoNoFacturable())->toBeNull()
            ->and(Cotizacion::porFacturar()->count())->toBe(1);
    });
});

describe('vistas', function () {
    it('el detalle y la vista previa ofrecen aceptar con el contacto precargado', function () {
        $this->actingAs($this->user)->get("/cotizaciones/{$this->cotizacion->id}")
            ->assertOk()
            ->assertSee('#dialogo-aceptar')
            ->assertSee(route('cotizaciones.aceptar', $this->cotizacion))
            ->assertSee('4491112233')
            ->assertSee('Diseño especial', false);

        $this->actingAs($this->user)->get("/cotizaciones/{$this->cotizacion->id}/vista-previa")
            ->assertOk()
            ->assertSee(route('cotizaciones.aceptar', $this->cotizacion));
    });

    it('la ventana avisa del faltante de existencias', function () {
        marcarExistencia($this->articulo, 1);

        $this->actingAs($this->user)->get("/cotizaciones/{$this->cotizacion->id}")
            ->assertSee('quedará faltante de:', false)
            ->assertSee(($this->articulo->modelo ?: $this->articulo->nombre).' (faltan 2)');
    });

    it('con pagos no se ofrece aceptar', function () {
        $this->cotizacion->pagos()->create(['tipo' => 'anticipo', 'fecha_pago' => today(), 'monto' => '10.00', 'cuenta_id' => Cuenta::factory()->for($this->user)->create()->id]);

        $this->actingAs($this->user)->get("/cotizaciones/{$this->cotizacion->id}")
            ->assertOk()
            ->assertDontSee(route('cotizaciones.aceptar', $this->cotizacion));
    });

    it('la bandeja tiene la etiqueta Aceptada', function () {
        $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/aceptar", datosAceptar());

        $this->actingAs($this->user)->get('/cotizaciones?estado=aceptada')
            ->assertOk()
            ->assertSee($this->cotizacion->folio_formateado);
    });

    it('el listado de ventas filtra por origen y marca las de cotización', function () {
        $this->actingAs($this->user)->post('/pedidos', datosPedido([lineaPedido(null)], ['cliente_nombre' => 'Cliente de mostrador']));
        $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/aceptar", datosAceptar());

        $this->actingAs($this->user)->get('/pedidos?origen=cotizacion')
            ->assertOk()
            ->assertSee('Ana López')
            ->assertSee('De la cotización '.$this->cotizacion->folio_formateado)
            ->assertDontSee('Cliente de mostrador');

        $this->actingAs($this->user)->get('/pedidos?origen=mostrador')
            ->assertSee('Cliente de mostrador')
            ->assertDontSee('Ana López');
    });
});
