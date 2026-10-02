<?php

use App\Enums\EstadoCotizacion;
use App\Enums\EstadoFactura;
use App\Enums\EstadoOrdenCompra;
use App\Enums\MotivoMovimientoInventario;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Existencia;
use App\Models\Factura;
use App\Models\MovimientoInventario;
use App\Models\OrdenCompra;
use App\Models\User;
use App\Services\Facturacion\TimbradorFacturas;
use App\Services\Inventario\RegistradorInventario;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.facturapi.llave' => 'sk_test_prueba',
        'services.facturapi.emisor' => ['rfc' => 'EKU9003173C9', 'razon_social' => 'ESCUELA KEMPER URGATE', 'regimen_fiscal' => '601', 'codigo_postal' => '26015'],
    ]);

    $this->user = User::factory()->create();
    $this->cliente = Cliente::factory()->for($this->user)->create();
    $this->articulo = articuloFacturable($this->user);

    $this->facturaCon = function (int $cantidad, array $atributos = []): Factura {
        $factura = Factura::factory()->for($this->cliente)->create(['user_id' => $this->user->id, ...$atributos]);
        agregarLinea($factura, $this->articulo, $cantidad);

        return $factura;
    };

    $this->timbrar = function (Factura $factura) {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado())]);

        return app(TimbradorFacturas::class)->timbrar($factura);
    };
});

describe('recepción de órdenes de compra', function () {
    beforeEach(function () {
        $this->orden = OrdenCompra::factory()->for($this->articulo->proveedor)->enEstado(EstadoOrdenCompra::Pagada)->create();
        agregarLinea($this->orden, $this->articulo, 10);
        agregarLinea($this->orden, null, 1);
    });

    it('suma la orden y da de alta en 0 el artículo que no estaba', function () {
        $this->actingAs($this->user)->post("/ordenes-compra/{$this->orden->id}/recibir")->assertSessionHas('exito');

        expect($this->orden->fresh()->estado)->toBe(EstadoOrdenCompra::Recibida)
            ->and(Existencia::sole())->articulo_id->toBe($this->articulo->id)->existencia->toBe(10)
            ->and(MovimientoInventario::sole())
            ->motivo->toBe(MotivoMovimientoInventario::RecepcionOrden)
            ->documentable_type->toBe('orden_compra');
    });

    it('con faltante pendiente salda primero el faltante', function () {
        marcarExistencia($this->articulo, 0, faltante: 3);

        $this->actingAs($this->user)->post("/ordenes-compra/{$this->orden->id}/recibir");

        expect(Existencia::sole())->existencia->toBe(7)->faltante_pendiente->toBe(0);
    });

    it('recibir dos veces suma una sola vez', function () {
        $this->actingAs($this->user)->post("/ordenes-compra/{$this->orden->id}/recibir");
        $this->actingAs($this->user)->post("/ordenes-compra/{$this->orden->id}/recibir")->assertSessionHas('error');

        expect(Existencia::sole()->existencia)->toBe(10)
            ->and(MovimientoInventario::count())->toBe(1);
    });

    it('una orden que no está pagada no mueve nada', function () {
        $this->orden->forceFill(['estado' => EstadoOrdenCompra::Enviada])->save();

        $this->actingAs($this->user)->post("/ordenes-compra/{$this->orden->id}/recibir");

        expect(Existencia::count())->toBe(0);
    });
});

describe('timbrado de facturas', function () {
    it('una factura sin cotización descuenta al timbrarse, dejando faltante si no alcanza', function () {
        marcarExistencia($this->articulo, 2);
        $factura = ($this->facturaCon)(5);

        ($this->timbrar)($factura);

        expect($factura->fresh()->estado)->toBe(EstadoFactura::Timbrada)
            ->and(Existencia::sole())->existencia->toBe(0)->faltante_pendiente->toBe(3)
            ->and(MovimientoInventario::sole())->motivo->toBe(MotivoMovimientoInventario::VentaFactura)->documentable_type->toBe('factura');
    });

    it('una factura con cotización no descuenta al timbrarse', function () {
        marcarExistencia($this->articulo, 5);
        $cotizacion = Cotizacion::factory()->for($this->cliente)->enEstado(EstadoCotizacion::Pagada)->create(['user_id' => $this->user->id]);
        $factura = ($this->facturaCon)(2, ['cotizacion_id' => $cotizacion->id]);

        ($this->timbrar)($factura);

        expect(Existencia::sole()->existencia)->toBe(5)
            ->and(MovimientoInventario::count())->toBe(0);
    });

    it('una factura suelta con un artículo sin fila no lo mueve ni lo da de alta', function () {
        ($this->timbrar)(($this->facturaCon)(2));

        expect(Existencia::count())->toBe(0)
            ->and(MovimientoInventario::count())->toBe(0);
    });

    it('un timbrado fallido no descuenta; el reintento exitoso descuenta una sola vez', function () {
        marcarExistencia($this->articulo, 10);
        $factura = ($this->facturaCon)(4);

        Http::fakeSequence('www.facturapi.io/v2/invoices')
            ->push(['message' => 'Servicio no disponible'], 503)
            ->push(respuestaTimbrado());

        app(TimbradorFacturas::class)->timbrar($factura);
        expect($factura->fresh()->estado)->toBe(EstadoFactura::Pendiente)
            ->and(Existencia::sole()->existencia)->toBe(10);

        app(TimbradorFacturas::class)->timbrar($factura);
        app(TimbradorFacturas::class)->timbrar($factura);

        expect($factura->fresh()->estado)->toBe(EstadoFactura::Timbrada)
            ->and(Existencia::sole()->existencia)->toBe(6)
            ->and(MovimientoInventario::count())->toBe(1);
    });
});

describe('cotización entregada', function () {
    beforeEach(function () {
        $this->cotizacion = Cotizacion::factory()->for($this->cliente)->enEstado(EstadoCotizacion::Pagada)->create(['user_id' => $this->user->id]);
    });

    it('descuenta al marcarse entregada', function () {
        marcarExistencia($this->articulo, 5);
        agregarLinea($this->cotizacion, $this->articulo, 3);
        agregarLinea($this->cotizacion, null, 1);

        $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/entregar");

        expect($this->cotizacion->fresh()->estado)->toBe(EstadoCotizacion::ProductoEntregado)
            ->and(Existencia::sole()->existencia)->toBe(2)
            ->and(MovimientoInventario::sole())->motivo->toBe(MotivoMovimientoInventario::VentaCotizacion)->documentable_type->toBe('cotizacion');
    });

    it('da de alta en 0 el artículo sin fila y deja el faltante', function () {
        agregarLinea($this->cotizacion, $this->articulo, 3);

        $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/entregar");

        expect(Existencia::sole())->existencia->toBe(0)->faltante_pendiente->toBe(3);
    });

    it('entregar dos veces descuenta una sola vez', function () {
        marcarExistencia($this->articulo, 5);
        agregarLinea($this->cotizacion, $this->articulo, 1);

        $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/entregar");
        $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/entregar")->assertSessionHas('error');

        expect(Existencia::sole()->existencia)->toBe(4);
    });

    it('la factura de esa cotización no descuenta y la entrega sí', function () {
        marcarExistencia($this->articulo, 5);
        agregarLinea($this->cotizacion, $this->articulo, 2);
        ($this->timbrar)(($this->facturaCon)(2, ['cotizacion_id' => $this->cotizacion->id]));

        $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/entregar");

        expect(Existencia::sole()->existencia)->toBe(3)
            ->and(MovimientoInventario::sole()->motivo)->toBe(MotivoMovimientoInventario::VentaCotizacion);
    });
});

describe('cancelación de facturas', function () {
    it('devuelve lo que la factura sacó, saldando primero el faltante', function () {
        marcarExistencia($this->articulo, 10);
        $primera = ($this->facturaCon)(5);
        ($this->timbrar)($primera);
        ($this->timbrar)(($this->facturaCon)(8));

        expect(Existencia::sole())->existencia->toBe(0)->faltante_pendiente->toBe(3);

        Http::fake(['www.facturapi.io/v2/invoices/*' => Http::response(['status' => 'canceled', 'cancellation_status' => 'accepted'])]);
        $this->actingAs($this->user)->post("/facturas/{$primera->id}/cancelar", ['motivo_cancelacion' => '02']);

        expect($primera->fresh()->estado)->toBe(EstadoFactura::Cancelada)
            ->and(Existencia::sole())->existencia->toBe(2)->faltante_pendiente->toBe(0)
            ->and(MovimientoInventario::latest('id')->first())
            ->motivo->toBe(MotivoMovimientoInventario::CancelacionFactura)
            ->cantidad->toBe(5);
    });

    it('una cancelación pendiente no devuelve; el refresco aceptado devuelve una sola vez', function () {
        marcarExistencia($this->articulo, 10);
        $factura = ($this->facturaCon)(4);
        ($this->timbrar)($factura);

        Http::fakeSequence('www.facturapi.io/v2/invoices/*')
            ->push(['status' => 'valid', 'cancellation_status' => 'pending'])
            ->push(['status' => 'canceled', 'cancellation_status' => 'accepted'])
            ->push(['status' => 'canceled', 'cancellation_status' => 'accepted']);

        $this->actingAs($this->user)->post("/facturas/{$factura->id}/cancelar", ['motivo_cancelacion' => '03']);
        expect(Existencia::sole()->existencia)->toBe(6);

        $this->actingAs($this->user)->get("/facturas/{$factura->id}")->assertOk();
        expect($factura->fresh()->estado)->toBe(EstadoFactura::Cancelada)
            ->and(Existencia::sole()->existencia)->toBe(10);

        // Un segundo aviso de "aceptada" (otro refresco, otra pestaña) no devuelve otra vez.
        app(RegistradorInventario::class)->devolverFactura($factura->fresh());

        expect(Existencia::sole()->existencia)->toBe(10)
            ->and(MovimientoInventario::where('motivo', MotivoMovimientoInventario::CancelacionFactura)->count())->toBe(1);
    });

    it('una factura con cotización no devuelve nada al cancelarse', function () {
        marcarExistencia($this->articulo, 5);
        $cotizacion = Cotizacion::factory()->for($this->cliente)->enEstado(EstadoCotizacion::Pagada)->create(['user_id' => $this->user->id]);
        $factura = ($this->facturaCon)(2, ['cotizacion_id' => $cotizacion->id]);
        ($this->timbrar)($factura);

        Http::fake(['www.facturapi.io/v2/invoices/*' => Http::response(['status' => 'canceled', 'cancellation_status' => 'accepted'])]);
        $this->actingAs($this->user)->post("/facturas/{$factura->id}/cancelar", ['motivo_cancelacion' => '02']);

        expect(Existencia::sole()->existencia)->toBe(5)
            ->and(MovimientoInventario::count())->toBe(0);
    });

    it('no da de alta al cancelar lo que no salió al timbrar', function () {
        $factura = ($this->facturaCon)(2);
        ($this->timbrar)($factura);

        Http::fake(['www.facturapi.io/v2/invoices/*' => Http::response(['status' => 'canceled', 'cancellation_status' => 'accepted'])]);
        $this->actingAs($this->user)->post("/facturas/{$factura->id}/cancelar", ['motivo_cancelacion' => '02']);

        expect(Existencia::count())->toBe(0)
            ->and(MovimientoInventario::count())->toBe(0);
    });
});
