<?php

use App\Enums\EstadoCotizacion;
use App\Enums\EstadoFactura;
use App\Enums\EstadoOrdenTrabajo;
use App\Enums\EstadoPedido;
use App\Enums\MotivoMovimientoInventario;
use App\Enums\TipoDescuento;
use App\Enums\TipoPago;
use App\Models\Articulo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionPago;
use App\Models\Cuenta;
use App\Models\Existencia;
use App\Models\Factura;
use App\Models\Movimiento;
use App\Models\MovimientoInventario;
use App\Models\OrdenTrabajo;
use App\Models\Pedido;
use App\Models\PedidoPago;
use App\Models\User;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use Illuminate\Support\Facades\Route;

/**
 * Cotización con descuento global de $5 y una línea por cada [artículo o null
 * (libre), cantidad, precio]. La primera lleva 10% de descuento.
 *
 * @param  list<array{0: Articulo|null, 1: int, 2: string}>  $lineas
 */
function cotizacionParaCobrar(User $user, Cliente $cliente, array $lineas, EstadoCotizacion $estado = EstadoCotizacion::Enviada): Cotizacion
{
    $cotizacion = Cotizacion::factory()->for($cliente)->enEstado($estado)
        ->create(['user_id' => $user->id, 'descuento_global_tipo' => TipoDescuento::Monto, 'descuento_global_valor' => '5.00']);

    $datos = array_map(fn (array $linea) => [
        'articulo_id' => $linea[0]?->id,
        'cantidad' => $linea[1],
        'descripcion' => $linea[0]->nombre ?? 'Diseño especial',
        'modelo' => $linea[0]?->modelo,
        'precio_unitario' => $linea[2],
        'descuento_tipo' => null,
        'descuento_valor' => null,
        'tasa_iva' => '16',
    ], $lineas);
    $datos[0]['descuento_tipo'] = 'porcentaje';
    $datos[0]['descuento_valor'] = '10.00';
    $totales = CalculadoraTotalesDocumento::calcular($datos, 'monto', '5.00');

    foreach ($datos as $i => $linea) {
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
 * La línea del formulario de cotización para un artículo (o libre).
 *
 * @return array<string, mixed>
 */
function lineaParaEditar(?Articulo $articulo, int $cantidad, string $precio = '80.00'): array
{
    return [
        'articulo_id' => $articulo?->id,
        'cantidad' => $cantidad,
        'descripcion' => $articulo->nombre ?? 'Diseño especial',
        'modelo' => $articulo?->modelo,
        'precio_unitario' => $precio,
        'descuento_tipo' => '',
        'descuento_valor' => '',
        'tasa_iva' => '16',
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->cliente = Cliente::factory()->for($this->user)->create(['telefono' => '+524491112233']);
    $this->cuenta = Cuenta::factory()->for($this->user)->create();

    // Sello: catálogo de producción. Cojín: suministro.
    $this->sello = articuloFacturable($this->user);
    $this->sello->catalogo->update(['requiere_produccion' => true]);
    $this->cojin = articuloFacturable($this->user);

    $this->cotizacion = cotizacionParaCobrar($this->user, $this->cliente, [[$this->sello, 3, '80.00'], [$this->cojin, 2, '50.00']]);

    $this->pagar = fn (Cotizacion $cotizacion, string $tipo, array $extra = []) => $this->actingAs($this->user)->post("/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => $tipo,
        'cuenta_id' => $this->cuenta->id,
        'fecha_pago' => today('America/Mexico_City')->toDateString(),
        'cliente_nombre' => 'Ana López',
        'cliente_telefono' => '449 765 4321',
        'cliente_correo' => 'ana@example.com',
        ...$extra,
    ]);
});

describe('acceso', function () {
    it('pide sesión y una cotización ajena responde 404', function () {
        $this->post("/cotizaciones/{$this->cotizacion->id}/pagos", ['tipo' => 'pago_total'])->assertRedirect('/login');

        $this->actingAs(User::factory()->create())
            ->post("/cotizaciones/{$this->cotizacion->id}/pagos", ['tipo' => 'pago_total'])
            ->assertNotFound();

        expect(Pedido::count())->toBe(0);
    });
});

describe('la regla del primer pago', function () {
    it('un pago de $0 se rechaza y no crea nada; una cotización en $0 no admite pagos', function () {
        ($this->pagar)($this->cotizacion, 'anticipo', ['monto' => '0'])->assertSessionHasErrorsIn('pago', 'monto');

        $vacia = Cotizacion::factory()->for($this->cliente)->enEstado(EstadoCotizacion::Enviada)->create(['user_id' => $this->user->id]);
        ($this->pagar)($vacia, 'pago_total')->assertSessionHasErrorsIn('pago', 'monto');

        expect(Pedido::count())->toBe(0)
            ->and(CotizacionPago::count())->toBe(0);
    });

    it('solo suministros no crea venta, sea quien sea el cliente: la cotización sigue su flujo', function (bool $distribuidor) {
        $this->cliente->update(['es_distribuidor' => $distribuidor]);
        $cotizacion = cotizacionParaCobrar($this->user, $this->cliente, [[$this->cojin, 2, '50.00']]);

        ($this->pagar)($cotizacion, 'pago_total', ['cliente_nombre' => '', 'cliente_telefono' => ''])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('exito', fn (string $mensaje) => str_ends_with($mensaje, 'Solo suministros: no se creó venta.'));

        expect(Pedido::count())->toBe(0)
            ->and(OrdenTrabajo::count())->toBe(0)
            ->and($cotizacion->fresh()->estado)->toBe(EstadoCotizacion::Pagada);

        $this->actingAs($this->user)->post("/cotizaciones/{$cotizacion->id}/entregar")->assertSessionHas('exito');

        expect(MovimientoInventario::sole()->motivo)->toBe(MotivoMovimientoInventario::VentaCotizacion);
    })->with(['cliente normal' => false, 'distribuidor' => true]);

    it('un distribuidor con artículos de producción no crea venta ni orden', function () {
        $this->cliente->update(['es_distribuidor' => true]);

        ($this->pagar)($this->cotizacion, 'anticipo', ['monto' => '100.00', 'cliente_nombre' => ''])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('exito', fn (string $mensaje) => str_ends_with($mensaje, 'Cliente distribuidor: no se creó venta.'));

        expect(Pedido::count())->toBe(0)
            ->and(OrdenTrabajo::count())->toBe(0)
            ->and($this->cotizacion->fresh()->estado)->toBe(EstadoCotizacion::Enviada);
    });

    it('un cliente normal con producción crea la venta con todas las líneas y su orden con las de producción', function () {
        $respuesta = ($this->pagar)($this->cotizacion, 'anticipo', ['monto' => '100.00']);

        $venta = Pedido::sole();
        $respuesta->assertRedirect(route('cotizaciones.show', $this->cotizacion))
            ->assertSessionHas('exito', 'Anticipo de $100.00 registrado. Se creó la venta PED-0001 y su orden de trabajo.')
            ->assertSessionHas('exito_enlaces', [
                'Ver venta' => route('pedidos.show', $venta),
                'Ver orden de trabajo' => route('pedidos.orden-trabajo.show', $venta),
            ]);

        expect($venta)
            ->estado->toBe(EstadoPedido::Anticipo)
            ->cobro_en_cotizacion->toBeTrue()
            ->cotizacion_id->toBe($this->cotizacion->id)
            ->cliente_id->toBe($this->cliente->id)
            ->cliente_nombre->toBe('Ana López')
            ->cliente_telefono->toBe('+524497654321')
            ->descuento_global_valor->toBe('5.00')
            ->total->toBe($this->cotizacion->total)
            ->and($venta->lineas)->toHaveCount(2)
            ->and($venta->lineas[0]->costo_unitario)->toBe($this->sello->fresh()->costo_con_descuento)
            ->and($venta->totalPagado())->toBe('100.00');

        $orden = $venta->ordenTrabajo;
        expect($orden->estado)->toBe(EstadoOrdenTrabajo::EnDibujo)
            ->and($orden->lineas)->toHaveCount(0)
            ->and($orden->imagen_ruta)->toBeNull()
            ->and($venta->lineasDeTrabajo()->pluck('articulo_id')->all())->toBe([$this->sello->id])
            ->and($orden->motivoNoAvanza())->toBe("Falta el color de tinta de: {$this->sello->nombre}.");

        $cotizacion = $this->cotizacion->fresh();
        expect($cotizacion->estado)->toBe(EstadoCotizacion::Aceptada)
            ->and($cotizacion->aceptada_en)->not->toBeNull()
            ->and($this->cliente->fresh()->telefono)->toBe('+524491112233');
    });

    it('una línea libre cuenta como producción', function () {
        $cotizacion = cotizacionParaCobrar($this->user, $this->cliente, [[$this->cojin, 1, '50.00'], [null, 1, '120.00']]);

        ($this->pagar)($cotizacion, 'pago_total')->assertSessionHasNoErrors();

        expect(Pedido::sole()->lineasDeTrabajo()->pluck('descripcion')->all())->toBe(['Diseño especial']);
    });

    it('el primer pago se admite en borrador', function () {
        $this->cotizacion->forceFill(['estado' => EstadoCotizacion::Borrador])->saveQuietly();

        ($this->pagar)($this->cotizacion, 'anticipo', ['monto' => '100.00'])->assertSessionHasNoErrors();

        expect($this->cotizacion->fresh()->estado)->toBe(EstadoCotizacion::Aceptada)
            ->and(Pedido::count())->toBe(1);
    });

    it('exige los datos de la venta solo cuando nace la venta', function () {
        ($this->pagar)($this->cotizacion, 'anticipo', ['monto' => '100.00', 'cliente_nombre' => '', 'cliente_telefono' => '123'])
            ->assertSessionHasErrorsIn('pago', ['cliente_nombre', 'cliente_telefono']);

        expect(Pedido::count())->toBe(0)
            ->and(CotizacionPago::count())->toBe(0);
    });
});

describe('los pagos siguientes', function () {
    beforeEach(function () {
        ($this->pagar)($this->cotizacion, 'anticipo', ['monto' => '100.00']);
        $this->venta = Pedido::sole();
    });

    it('se registran en la cotización: no crean otra venta y la venta queda pagada', function () {
        ($this->pagar)($this->cotizacion, 'saldo', ['cliente_nombre' => ''])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('exito_enlaces', []);

        $venta = $this->venta->fresh();
        expect(Pedido::count())->toBe(1)
            ->and(OrdenTrabajo::count())->toBe(1)
            ->and($venta->estado)->toBe(EstadoPedido::Pagado)
            ->and($venta->autofactura_token)->not->toBeNull()
            ->and($this->cotizacion->fresh()->estado)->toBe(EstadoCotizacion::Aceptada);
    });

    it('cada pago es un solo ingreso en Tesorería, el de la cotización', function () {
        ($this->pagar)($this->cotizacion, 'saldo');

        expect(Movimiento::count())->toBe(2)
            ->and(PedidoPago::count())->toBe(0)
            ->and(Movimiento::pluck('documentable_type')->unique()->all())->toBe([(new CotizacionPago)->getMorphClass()]);
    });

    it('la venta no recibe pagos propios ni se edita: se corrige en la cotización', function () {
        $this->actingAs($this->user)
            ->post("/pedidos/{$this->venta->id}/pagos", ['cuenta_id' => $this->cuenta->id, 'fecha_pago' => today('America/Mexico_City')->toDateString(), 'monto' => '10.00'])
            ->assertSessionHasErrorsIn('pago', ['monto' => "Los pagos se registran en la cotización {$this->cotizacion->folio_formateado}."]);

        $this->actingAs($this->user)->get("/pedidos/{$this->venta->id}/editar")
            ->assertForbidden()
            ->assertSee("Los artículos se corrigen en la cotización {$this->cotizacion->folio_formateado}.");

        $this->actingAs($this->user)->get("/pedidos/{$this->venta->id}")
            ->assertOk()
            ->assertSee('Los pagos se registran en la cotización')
            ->assertSee('$100.00')
            ->assertDontSee('#dialogo-pago');

        expect(PedidoPago::count())->toBe(0);
    });

    it('borrar el último pago conserva la venta y la orden; borrar la venta regresa la cotización y el siguiente pago vuelve a aplicar la regla', function () {
        $pago = $this->cotizacion->pagos()->sole();

        $this->actingAs($this->user)->delete("/cotizaciones/{$this->cotizacion->id}/pagos/{$pago->id}")->assertSessionHas('exito');

        expect($this->venta->fresh()->estado)->toBe(EstadoPedido::Pendiente)
            ->and(OrdenTrabajo::count())->toBe(1);

        $this->actingAs($this->user)->delete("/pedidos/{$this->venta->id}")
            ->assertSessionHas('exito', fn (string $mensaje) => str_contains($mensaje, 'volvió a Enviada'));

        expect(Pedido::count())->toBe(0)
            ->and(OrdenTrabajo::count())->toBe(0)
            ->and($this->cotizacion->fresh()->estado)->toBe(EstadoCotizacion::Enviada);

        ($this->pagar)($this->cotizacion, 'anticipo', ['monto' => '50.00'])->assertSessionHasNoErrors();

        expect(Pedido::count())->toBe(1)
            ->and(OrdenTrabajo::count())->toBe(1);
    });

    it('con pagos, la venta no se borra', function () {
        $this->actingAs($this->user)->delete("/pedidos/{$this->venta->id}")
            ->assertSessionHas('error', "La cotización {$this->cotizacion->folio_formateado} tiene pagos registrados: elimínalos antes de borrar la venta.");

        expect(Pedido::count())->toBe(1);
    });

    it('no se elimina el pago de una venta entregada ni facturada', function () {
        $pago = $this->cotizacion->pagos()->sole();
        Factura::factory()->for($this->cliente)->create(['user_id' => $this->user->id, 'pedido_id' => $this->venta->id]);

        $this->actingAs($this->user)->delete("/cotizaciones/{$this->cotizacion->id}/pagos/{$pago->id}")
            ->assertSessionHas('error', fn (string $mensaje) => str_contains($mensaje, 'cancélala antes de quitar el pago'));

        Factura::query()->update(['estado' => EstadoFactura::Cancelada->value]);
        $this->venta->forceFill(['estado' => EstadoPedido::Entregado, 'entregado_en' => now()])->save();

        $this->actingAs($this->user)->delete("/cotizaciones/{$this->cotizacion->id}/pagos/{$pago->id}")
            ->assertSessionHas('error', 'Los pagos de una venta entregada no se eliminan.');

        expect(CotizacionPago::count())->toBe(1);
    });

    it('no se registran pagos con la venta entregada', function () {
        $this->venta->forceFill(['estado' => EstadoPedido::Entregado, 'entregado_en' => now()])->save();

        ($this->pagar)($this->cotizacion, 'saldo')
            ->assertSessionHasErrorsIn('pago', ['monto' => 'La venta PED-0001 ya se entregó.']);
    });
});

describe('entregar la venta', function () {
    beforeEach(function () {
        ($this->pagar)($this->cotizacion, 'anticipo', ['monto' => '100.00']);
        $this->venta = Pedido::sole();
        $this->venta->ordenTrabajo->forceFill(['estado' => EstadoOrdenTrabajo::Terminado])->save();
    });

    it('cobra el saldo en la cotización, marcado al entregar, y no se puede deshacer', function () {
        $this->actingAs($this->user)->post("/pedidos/{$this->venta->id}/entregar", ['cuenta_id' => $this->cuenta->id])
            ->assertSessionHas('exito', fn (string $mensaje) => str_contains($mensaje, 'PED-0001 entregado.'));

        $cobro = CotizacionPago::orderByDesc('id')->first();
        expect($cobro)
            ->tipo->toBe(TipoPago::Saldo)
            ->registrado_al_entregar->toBeTrue()
            ->and($this->cotizacion->fresh()->saldoPendiente())->toBe('0.00')
            ->and($this->venta->fresh()->estado)->toBe(EstadoPedido::Entregado)
            ->and(PedidoPago::count())->toBe(0)
            ->and(Movimiento::count())->toBe(2);

        $this->actingAs($this->user)->post("/pedidos/{$this->venta->id}/deshacer-entrega")
            ->assertSessionHas('error', 'Esta entrega registró un cobro: corrígelo desde el detalle del pedido.');
    });
});

describe('editar la cotización con venta', function () {
    beforeEach(function () {
        ($this->pagar)($this->cotizacion, 'anticipo', ['monto' => '100.00']);
        $this->venta = Pedido::sole();
        $linea = $this->venta->lineas()->where('articulo_id', $this->sello->id)->sole();
        $this->actingAs($this->user)->put("/pedidos/{$this->venta->id}/orden-trabajo", ['colores' => [$linea->id => ['color' => 'azul']]])->assertSessionHasNoErrors();
        $this->editar = fn (array $lineas, array $cambios = []) => $this->actingAs($this->user)->put("/cotizaciones/{$this->cotizacion->id}", [
            'cliente_id' => $this->cliente->id,
            'lineas' => $lineas,
            'descuento_global_tipo' => '',
            'descuento_global_valor' => '',
            ...$cambios,
        ]);
    });

    it('sigue editable y le copia los cambios a la venta, con inventario y colores', function () {
        $this->actingAs($this->user)->get("/cotizaciones/{$this->cotizacion->id}/editar")
            ->assertOk()
            ->assertSee('Los cambios se copian a la venta PED-0001.')
            ->assertSee('El cliente ya no se cambia: la cotización tiene venta.');

        ($this->editar)([lineaParaEditar($this->sello, 5), lineaParaEditar($this->cojin, 1, '50.00'), lineaParaEditar(null, 1, '30.00')])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('exito', fn (string $mensaje) => str_ends_with($mensaje, 'Los cambios se copiaron a la venta PED-0001.'));

        $cotizacion = $this->cotizacion->fresh();
        $venta = $this->venta->fresh();
        expect($cotizacion->estado)->toBe(EstadoCotizacion::Aceptada)
            ->and($venta->total)->toBe($cotizacion->total)
            ->and($venta->lineas)->toHaveCount(3)
            ->and($venta->lineas[0]->cantidad)->toBe(5)
            ->and($venta->cliente_nombre)->toBe('Ana López')
            ->and(Existencia::where('articulo_id', $this->sello->id)->sole()->faltante_pendiente)->toBe(5)
            ->and(Existencia::where('articulo_id', $this->cojin->id)->sole()->faltante_pendiente)->toBe(1);

        // El sello conserva su color; la línea libre (producción) queda sin color.
        $orden = $venta->ordenTrabajo;
        expect($orden->lineas()->sole()->color_tinta->value)->toBe('azul')
            ->and($orden->lineasSinColor()->pluck('descripcion')->all())->toBe(['Diseño especial']);
    });

    it('no deja el total por debajo de lo pagado ni cambia el cliente', function () {
        ($this->editar)([lineaParaEditar($this->sello, 1, '10.00')])->assertSessionHasErrors('lineas');

        $otro = Cliente::factory()->for($this->user)->create();
        ($this->editar)([lineaParaEditar($this->sello, 3)], ['cliente_id' => $otro->id])->assertSessionHasErrors('cliente_id');

        expect($this->venta->fresh()->lineas()->count())->toBe(2);
    });

    it('con la venta entregada o facturada ya no se edita', function (Closure $preparar, string $motivo) {
        $preparar($this);

        $this->actingAs($this->user)->get("/cotizaciones/{$this->cotizacion->id}/editar")
            ->assertForbidden()
            ->assertSee($motivo);
    })->with([
        'entregada' => [fn ($prueba) => $prueba->venta->forceFill(['estado' => EstadoPedido::Entregado, 'entregado_en' => now()])->save(), 'La venta PED-0001 ya se entregó: la cotización queda solo para consulta.'],
        'facturada' => [fn ($prueba) => Factura::factory()->for($prueba->cliente)->create(['user_id' => $prueba->user->id, 'pedido_id' => $prueba->venta->id]), 'Ya tiene la factura'],
    ]);

    it('no se elimina mientras tenga venta', function () {
        $this->actingAs($this->user)->delete("/cotizaciones/{$this->cotizacion->id}")
            ->assertSessionHas('error', 'La cotización tiene la venta PED-0001: bórrala antes.');

        expect(Cotizacion::count())->toBe(1);
    });

    it('no caduca', function () {
        $this->travel(Cotizacion::DIAS_CADUCIDAD + 5)->days();

        $this->artisan('cotizaciones:purgar-vencidas')->assertSuccessful();

        expect(Cotizacion::count())->toBe(1)
            ->and($this->cotizacion->fresh()->caducaEl())->toBeNull();
    });
});

describe('la orden de trabajo', function () {
    beforeEach(function () {
        ($this->pagar)($this->cotizacion, 'anticipo', ['monto' => '100.00']);
        $this->venta = Pedido::sole();
    });

    it('lleva solo las líneas de producción en el formulario y la validación', function () {
        $lineas = $this->venta->lineas;

        $this->actingAs($this->user)->get("/pedidos/{$this->venta->id}/orden-trabajo/editar")
            ->assertOk()
            ->assertSee($this->sello->nombre)
            ->assertDontSee("colores[{$lineas[1]->id}]", false);

        $this->actingAs($this->user)
            ->put("/pedidos/{$this->venta->id}/orden-trabajo", ['colores' => [$lineas[0]->id => ['color' => 'rojo'], $lineas[1]->id => ['color' => 'azul']]])
            ->assertSessionHasErrors('colores');
    });

    it('se puede crear a mano si la venta se quedó sin orden', function () {
        $this->venta->ordenTrabajo->delete();
        $linea = $this->venta->lineas()->where('articulo_id', $this->sello->id)->sole();

        $this->actingAs($this->user)
            ->post("/pedidos/{$this->venta->id}/orden-trabajo", ['colores' => [$linea->id => ['color' => 'negro']]])
            ->assertSessionHasNoErrors();

        expect(OrdenTrabajo::sole()->lineas()->sole()->pedido_linea_id)->toBe($linea->id);
    });

    it('cambiar la casilla del catálogo después no crea ni borra nada; la orden se lee en vivo', function () {
        $this->sello->catalogo->update(['requiere_produccion' => false]);

        $venta = $this->venta->fresh();
        expect(Pedido::count())->toBe(1)
            ->and(OrdenTrabajo::count())->toBe(1)
            ->and($venta->lineasDeTrabajo())->toHaveCount(0);

        $this->actingAs($this->user)->get("/pedidos/{$venta->id}/orden-trabajo")
            ->assertOk()
            ->assertSee('Esta orden ya no tiene artículos de producción.');
    });
});

describe('vistas', function () {
    it('la ventana del pago avisa qué va a pasar y pide los datos de la venta precargados', function () {
        $this->actingAs($this->user)->get("/cotizaciones/{$this->cotizacion->id}")
            ->assertOk()
            ->assertSee('data-aviso-destino="venta_y_orden"', false)
            ->assertSee('Al registrar este pago se creará la venta y su orden de trabajo.')
            ->assertSee('Datos de la venta')
            ->assertSee('4491112233')
            ->assertDontSee('#dialogo-aceptar');

        $this->cliente->update(['es_distribuidor' => true]);
        $this->actingAs($this->user)->get("/cotizaciones/{$this->cotizacion->id}/vista-previa")
            ->assertSee('Cliente distribuidor: no se creará venta ni orden de trabajo.')
            ->assertDontSee('Datos de la venta');
    });

    it('la ventana avisa del faltante de existencias', function () {
        marcarExistencia($this->sello, 1);

        $this->actingAs($this->user)->get("/cotizaciones/{$this->cotizacion->id}")
            ->assertSee('quedará faltante de:', false)
            ->assertSee(($this->sello->modelo ?: $this->sello->nombre).' (faltan 2)');
    });

    it('después del primer pago ya no hay aviso, y el detalle enlaza la venta y la orden', function () {
        ($this->pagar)($this->cotizacion, 'anticipo', ['monto' => '100.00']);

        $this->actingAs($this->user)->get("/cotizaciones/{$this->cotizacion->id}")
            ->assertOk()
            ->assertSee('Anticipo de $100.00 registrado. Se creó la venta PED-0001 y su orden de trabajo.')
            ->assertSee('Ver orden de trabajo')
            ->assertSee('Venta · PED-0001')
            ->assertSee('Los pagos se registran aquí')
            ->assertSee('Registrar saldo')
            ->assertSee(route('cotizaciones.edit', $this->cotizacion))
            ->assertDontSee('data-aviso-destino', false);
    });

    it('ya no existe Aceptar', function () {
        expect(Route::has('cotizaciones.aceptar'))->toBeFalse();
    });

    it('la bandeja tiene la etiqueta Aceptada y el listado de ventas suma los pagos de la cotización', function () {
        ($this->pagar)($this->cotizacion, 'anticipo', ['monto' => '100.00']);

        $this->actingAs($this->user)->get('/cotizaciones?estado=aceptada')
            ->assertOk()
            ->assertSee($this->cotizacion->folio_formateado);

        $this->actingAs($this->user)->get('/pedidos?origen=cotizacion')
            ->assertOk()
            ->assertSee('Ana López')
            ->assertSee('$100.00');
    });
});

describe('las ventas de "Aceptar" (021)', function () {
    it('conservan sus pagos propios', function () {
        $this->cotizacion->forceFill(['estado' => EstadoCotizacion::Aceptada])->saveQuietly();
        $venta = Pedido::factory()->conLinea(1, '100.00')->create(['user_id' => $this->user->id]);
        $venta->forceFill(['cotizacion_id' => $this->cotizacion->id, 'cliente_id' => $this->cliente->id])->save();

        $this->actingAs($this->user)
            ->post("/pedidos/{$venta->id}/pagos", ['cuenta_id' => $this->cuenta->id, 'fecha_pago' => today('America/Mexico_City')->toDateString(), 'monto' => '10.00'])
            ->assertSessionHasNoErrors();

        ($this->pagar)($this->cotizacion, 'anticipo', ['monto' => '10.00'])
            ->assertSessionHasErrorsIn('pago', ['monto' => 'Los pagos se registran en su venta PED-0001.']);

        expect($venta->fresh()->cobraEnCotizacion())->toBeFalse()
            ->and(PedidoPago::count())->toBe(1);
    });
});

describe('catálogos y artículos', function () {
    it('la casilla "Requiere producción" se guarda y se ve en el catálogo y en el artículo', function () {
        $catalogo = $this->cojin->catalogo;

        $this->actingAs($this->user)->put("/catalogos/{$catalogo->id}", [
            'nombre' => $catalogo->nombre,
            'descuento' => $catalogo->descuento,
            'utilidad_porcentaje' => $catalogo->utilidad_porcentaje,
            'utilidad_distribuidor_porcentaje' => $catalogo->utilidad_distribuidor_porcentaje,
            'requiere_produccion' => '1',
        ])->assertSessionHasNoErrors();

        expect($catalogo->fresh()->requiere_produccion)->toBeTrue()
            ->and($this->cojin->fresh()->requiereProduccion())->toBeTrue();

        $this->actingAs($this->user)->get('/catalogos')->assertSee('etiqueta-produccion', false);
        $this->actingAs($this->user)->get("/articulos/{$this->cojin->id}/editar")->assertSee('Producción (por su catálogo)');

        $this->actingAs($this->user)->put("/catalogos/{$catalogo->id}", [
            'nombre' => $catalogo->nombre,
            'descuento' => $catalogo->descuento,
            'utilidad_porcentaje' => $catalogo->utilidad_porcentaje,
            'utilidad_distribuidor_porcentaje' => $catalogo->utilidad_distribuidor_porcentaje,
        ])->assertSessionHasNoErrors();

        expect($catalogo->fresh()->requiere_produccion)->toBeFalse();
    });
});
