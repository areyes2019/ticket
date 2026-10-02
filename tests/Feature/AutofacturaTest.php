<?php

use App\Enums\EstadoFactura;
use App\Enums\EstadoPedido;
use App\Enums\FormaPago;
use App\Enums\MetodoPago;
use App\Enums\MotivoMovimientoInventario;
use App\Enums\TipoCuenta;
use App\Mail\FacturaMail;
use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Existencia;
use App\Models\Factura;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\User;
use App\Services\Inventario\RegistradorInventario;
use App\Services\Pedidos\Autofacturador;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * @return array<string, string>
 */
function datosFiscales(array $cambios = []): array
{
    return [
        'rfc' => 'xaxx010101000',
        'razon_social' => 'PUBLICO EN GENERAL',
        'regimen_fiscal' => '616',
        'codigo_postal_fiscal' => '20000',
        'uso_cfdi' => 'S01',
        'correo' => 'cliente@example.com',
        ...$cambios,
    ];
}

beforeEach(function () {
    config([
        'services.facturapi.llave' => 'sk_test_prueba',
        'services.facturapi.emisor' => ['rfc' => 'EKU9003173C9', 'razon_social' => 'ESCUELA KEMPER URGATE', 'regimen_fiscal' => '601', 'codigo_postal' => '26015'],
    ]);
    Mail::fake();

    $this->user = User::factory()->create();
    $this->articulo = articuloFacturable($this->user);
    marcarExistencia($this->articulo, 10);
    $this->cuenta = Cuenta::factory()->for($this->user)->create(['tipo' => TipoCuenta::Efectivo]);

    // Pedido con una línea de catálogo (3 piezas) y una libre, totalmente pagado.
    $this->actingAs($this->user)->post('/pedidos', datosPedido([lineaPedido($this->articulo, 3, '100.00'), lineaPedido(null, 1, '50.00')]));
    $this->pedido = Pedido::sole();
    $this->actingAs($this->user)->post("/pedidos/{$this->pedido->id}/pagos", ['cuenta_id' => $this->cuenta->id, 'fecha_pago' => today('America/Mexico_City')->toDateString(), 'monto' => $this->pedido->total]);
    $this->pedido->refresh();
    auth()->logout();

    $this->url = '/autofactura/'.$this->pedido->autofactura_token;
});

it('responde sin sesión con el folio, la fecha y el total', function () {
    expect($this->pedido->estado)->toBe(EstadoPedido::Pagado);

    $this->get($this->url)->assertOk()
        ->assertSee('No. 0001')
        ->assertSee('$'.number_format((float) $this->pedido->total, 2))
        ->assertSee('Generar mi factura')
        ->assertDontSee('Cerrar sesión');
});

it('un token inexistente o con otro formato no lleva a ningún pedido', function () {
    $this->get('/autofactura/'.str_repeat('a', 64))->assertNotFound()->assertSee('Este enlace no es válido');
    $this->get('/autofactura/'.$this->pedido->id)->assertNotFound();
});

it('con saldo pendiente o vencido, el enlace explica el motivo y no ofrece el formulario', function () {
    $this->travelTo(now(config('app.zona_negocio'))->addMonthNoOverflow()->startOfMonth()->addHour());

    $this->get($this->url)->assertSee('El enlace para facturar venció')->assertDontSee('Generar mi factura');
    $this->post($this->url, datosFiscales())->assertSessionHas('error');

    expect(Factura::count())->toBe(0);
});

it('timbra la factura del pedido con sus mismos importes, PUE y forma de pago de la cuenta, sin mover inventario', function () {
    Http::fake([
        'www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado()),
        'www.facturapi.io/v2/invoices/*/xml' => Http::response('<cfdi/>'),
    ]);
    $movimientosAntes = MovimientoInventario::count();

    $this->post($this->url, datosFiscales())->assertRedirect($this->url)->assertSessionHas('exito');

    $factura = Factura::with('lineas')->sole();
    $cliente = Cliente::sole();

    expect($factura)
        ->estado->toBe(EstadoFactura::Timbrada)
        ->pedido_id->toBe($this->pedido->id)
        ->cliente_id->toBe($cliente->id)
        ->metodo_pago->toBe(MetodoPago::UnaExhibicion)
        ->forma_pago->toBe(FormaPago::Efectivo)
        ->total->toBe($this->pedido->total)
        ->and($cliente)->rfc->toBe('XAXX010101000')->user_id->toBe($this->user->id)->nombre_contacto->toBe('Juan Pérez')
        ->and($factura->lineas[1])->articulo_id->toBeNull()->clave_prod_serv->toBe('01010101')->clave_unidad->toBe('H87')
        ->and(MovimientoInventario::count())->toBe($movimientosAntes)
        ->and(Existencia::sole()->existencia)->toBe(7);

    Mail::assertSent(FacturaMail::class, fn (FacturaMail $correo) => $correo->hasTo('cliente@example.com'));

    Http::assertSent(fn ($peticion) => $peticion->method() === 'POST'
        && $peticion['payment_method'] === 'PUE'
        && $peticion['items'][1]['product']['product_key'] === '01010101');

    // Ya facturado: el enlace muestra la factura y no admite otra.
    $this->get($this->url)->assertSee('Descargar PDF')->assertDontSee('Generar mi factura');
    $this->post($this->url, datosFiscales())->assertSessionHas('error', 'Este pedido ya se facturó.');

    expect(Factura::count())->toBe(1);
});

it('reutiliza el cliente con ese RFC y le actualiza los datos fiscales', function () {
    Http::fake(['www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado()), 'www.facturapi.io/v2/invoices/*/xml' => Http::response('<cfdi/>')]);
    $existente = Cliente::factory()->for($this->user)->create(['rfc' => 'XAXX010101000', 'codigo_postal_fiscal' => '99999']);

    $this->post($this->url, datosFiscales());

    expect(Cliente::count())->toBe(1)
        ->and($existente->fresh()->codigo_postal_fiscal)->toBe('20000')
        ->and(Factura::sole()->cliente_id)->toBe($existente->id);
});

it('un timbrado rechazado explica el motivo, marca el pedido y el reintento usa la misma factura', function () {
    Http::fake([
        'www.facturapi.io/v2/invoices' => Http::sequence()
            ->push(['message' => 'El código postal no corresponde al RFC.'], 400)
            ->push(respuestaTimbrado()),
        'www.facturapi.io/v2/invoices/*/xml' => Http::response('<cfdi/>'),
    ]);

    $this->post($this->url, datosFiscales())
        ->assertRedirect($this->url)
        ->assertSessionHas('error', fn (string $mensaje) => str_contains($mensaje, 'El código postal no corresponde al RFC.'));

    $factura = Factura::sole();

    expect($factura->estado)->toBe(EstadoFactura::Pendiente)
        ->and($this->pedido->fresh()->autofactura_error)->toContain('código postal')
        ->and($factura->esEditable())->toBeFalse();

    $this->get($this->url)->assertSee('Generar mi factura');

    $this->post($this->url, datosFiscales(['codigo_postal_fiscal' => '20100']))->assertSessionHas('exito');

    expect(Factura::sole())->id->toBe($factura->id)->estado->toBe(EstadoFactura::Timbrada)
        ->and($this->pedido->fresh()->autofactura_error)->toBeNull();
});

it('una falla del servicio muestra un mensaje genérico', function () {
    Http::fake(['www.facturapi.io/v2/invoices' => Http::response(['message' => 'Servicio no disponible'], 503)]);

    $this->post($this->url, datosFiscales())->assertSessionHas('error', 'El servicio de facturación no responde. Intenta de nuevo en unos minutos.');
});

it('valida los datos fiscales', function () {
    $this->post($this->url, datosFiscales(['rfc' => 'NOESRFC', 'codigo_postal_fiscal' => '123', 'uso_cfdi' => 'CP01', 'correo' => 'x']))
        ->assertSessionHasErrors(['rfc', 'codigo_postal_fiscal', 'uso_cfdi', 'correo']);
});

it('cancelar la autofactura no devuelve existencias', function () {
    Http::fake(['www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado()), 'www.facturapi.io/v2/invoices/*/xml' => Http::response('<cfdi/>')]);
    $this->post($this->url, datosFiscales());

    $factura = Factura::sole();
    $factura->forceFill(['estado' => EstadoFactura::Cancelada])->save();
    app(RegistradorInventario::class)->devolverFactura($factura);

    expect(Existencia::sole()->existencia)->toBe(7)
        ->and(MovimientoInventario::where('motivo', MotivoMovimientoInventario::CancelacionFactura)->count())->toBe(0);
});

it('limita las llamadas al portal', function () {
    foreach (range(1, 20) as $intento) {
        $this->get($this->url)->assertOk();
    }

    $this->get($this->url)->assertTooManyRequests();
});

it('la factura de un pedido no se corrige desde Facturación', function () {
    Http::fake(['www.facturapi.io/v2/invoices' => Http::response(['message' => 'RFC inválido.'], 400)]);
    app(Autofacturador::class)->facturar($this->pedido, datosFiscales());

    $this->actingAs($this->user)->get('/facturas/'.Factura::sole()->id.'/editar')->assertForbidden();
});
