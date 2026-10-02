<?php

use App\Enums\ClaveConfiguracion;
use App\Models\Configuracion;
use App\Models\Cuenta;
use App\Models\Pedido;
use App\Models\User;
use App\Services\Pedidos\GeneradorTicketPedido;
use App\Services\Pedidos\MensajePedido;
use chillerlan\QRCode\Common\GDLuminanceSource;
use chillerlan\QRCode\QRCode;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->pedido = Pedido::factory()->for($this->user)->conLinea(3, '175.86')->create(['cliente_nombre' => 'Juan Pérez']);
    $this->cuenta = Cuenta::factory()->for($this->user)->create();
});

/**
 * Archivos bajo storage/app (para comprobar que el ticket no guarda nada).
 *
 * @return list<string>
 */
function archivosAlmacenados(): array
{
    return collect(File::allFiles(storage_path('app')))->map(fn ($archivo) => $archivo->getPathname())->sort()->values()->all();
}

it('el ticket es un JPEG de 576 px que no se guarda en ningún lado', function () {
    $antes = archivosAlmacenados();

    $primera = $this->actingAs($this->user)->get("/pedidos/{$this->pedido->id}/ticket");
    $this->actingAs($this->user)->get("/pedidos/{$this->pedido->id}/ticket?descargar=1")
        ->assertHeader('Content-Disposition', 'attachment; filename="ticket-PED-0001.jpg"');

    $primera->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('Content-Disposition', 'inline; filename="ticket-PED-0001.jpg"');
    expect($primera->headers->get('Cache-Control'))->toContain('no-store');

    $imagen = imagecreatefromstring($primera->getContent());

    expect($imagen)->not->toBeFalse()
        ->and(imagesx($imagen))->toBe(GeneradorTicketPedido::ANCHO)
        ->and(archivosAlmacenados())->toBe($antes);
});

it('el ticket lleva al pie un QR legible con la URL absoluta de la entrega', function () {
    $jpeg = app(GeneradorTicketPedido::class)->generar($this->pedido);
    $imagen = imagecreatefromstring($jpeg);

    // El QR ocupa el cuadro de 300 px sobre el número de ticket, al final.
    $pie = imagecreatetruecolor(imagesx($imagen), 420);
    imagecopy($pie, $imagen, 0, 0, 0, imagesy($imagen) - 420, imagesx($imagen), 420);

    $leido = trim((string) (new QRCode)->readFromSource(new GDLuminanceSource($pie)));

    expect($leido)->toBe(route('pedidos.entregar', $this->pedido))
        ->and($leido)->toStartWith('http');
});

it('el ticket se dibuja aunque el logo configurado no exista', function () {
    config(['negocio.logo' => 'negocio/no-existe.png', 'negocio.telefono' => '449 000 0000', 'negocio.domicilio' => 'Calle Uno 123, Centro']);

    expect(imagecreatefromstring(app(GeneradorTicketPedido::class)->generar($this->pedido)))->not->toBeFalse();
});

it('la etiqueta lleva QR, nombre, teléfono, número y saldo', function () {
    $this->actingAs($this->user)->get("/pedidos/{$this->pedido->id}/etiqueta")
        ->assertOk()
        ->assertSee('data:image/png;base64,', false)
        ->assertSee('Juan Pérez')
        ->assertSee($this->pedido->telefono_legible)
        ->assertSee('No. 0001')
        ->assertSee('SALDO: $611.99')
        ->assertSee('size: 50mm 25mm', false);

    $this->actingAs($this->user)->post("/pedidos/{$this->pedido->id}/pagos", ['cuenta_id' => $this->cuenta->id, 'fecha_pago' => today('America/Mexico_City')->toDateString(), 'monto' => '611.99']);

    $this->actingAs($this->user)->get("/pedidos/{$this->pedido->id}/etiqueta")->assertSee('PAGADO');
});

it('los mensajes resuelven sus huecos y dejan intactos los que no existen', function () {
    $this->user->configuraciones()->create(['clave' => ClaveConfiguracion::MensajeListo->value, 'valor' => 'Hola {nombre}, ticket {folio}: total {total}, pagado {pagado}, saldo {saldo} {otro}']);
    $this->actingAs($this->user)->post("/pedidos/{$this->pedido->id}/pagos", ['cuenta_id' => $this->cuenta->id, 'fecha_pago' => today('America/Mexico_City')->toDateString(), 'monto' => '100.00']);

    expect(app(MensajePedido::class)->resolver($this->pedido->fresh(), ClaveConfiguracion::MensajeListo))
        ->toBe('Hola Juan Pérez, ticket 0001: total $611.99, pagado $100.00, saldo $511.99 {otro}');
});

it('sin configuración sale el texto por defecto; vacío no sale mensaje', function () {
    $mensajes = app(MensajePedido::class);

    expect($mensajes->resolver($this->pedido, ClaveConfiguracion::MensajeListo))->toContain('Tu pedido con No. de ticket 0001 ya está listo')
        ->and($mensajes->resolver($this->pedido, ClaveConfiguracion::MensajeTicket))->toStartWith('¿Qué sigue ahora?');

    $this->user->configuraciones()->create(['clave' => ClaveConfiguracion::MensajeListo->value, 'valor' => null]);

    expect($mensajes->resolver($this->pedido, ClaveConfiguracion::MensajeListo))->toBeNull();
});

it('con el primer pago aparecen compartir ticket y avisar que está listo, con el mensaje resuelto', function () {
    $this->actingAs($this->user)->post("/pedidos/{$this->pedido->id}/pagos", ['cuenta_id' => $this->cuenta->id, 'fecha_pago' => today('America/Mexico_City')->toDateString(), 'monto' => '100.00']);

    $this->actingAs($this->user)->get("/pedidos/{$this->pedido->id}")
        ->assertSee('Compartir ticket')
        ->assertSee('data-tipo="image/jpeg"', false)
        ->assertSee('Avisar que está listo')
        ->assertSee('https://wa.me/?text='.rawurlencode('Hola Juan Pérez 👋'), false);
});

it('al quedar pagado ofrece el enlace de autofactura', function () {
    $this->actingAs($this->user)->post("/pedidos/{$this->pedido->id}/pagos", ['cuenta_id' => $this->cuenta->id, 'fecha_pago' => today('America/Mexico_City')->toDateString(), 'monto' => '611.99']);

    $this->actingAs($this->user)->get("/pedidos/{$this->pedido->id}")
        ->assertSee('Compartir enlace de autofactura')
        ->assertSee($this->pedido->fresh()->urlAutofactura());
});

it('configuración guarda los dos mensajes por usuario, acepta vacío y limita el largo', function () {
    $this->actingAs($this->user)->put('/configuracion', ['mensaje_ticket' => '', 'mensaje_listo' => 'Listo {nombre}'])->assertSessionHasNoErrors();

    $otro = User::factory()->create();

    expect(Configuracion::valor($this->user, ClaveConfiguracion::MensajeTicket))->toBeNull()
        ->and(Configuracion::valor($this->user, ClaveConfiguracion::MensajeListo))->toBe('Listo {nombre}')
        ->and(Configuracion::valor($otro, ClaveConfiguracion::MensajeListo))->toBe(ClaveConfiguracion::MensajeListo->valorPorDefecto());

    $this->actingAs($this->user)->put('/configuracion', ['mensaje_ticket' => str_repeat('a', 2001), 'mensaje_listo' => 'x'])->assertSessionHasErrors('mensaje_ticket');
    $this->actingAs($this->user)->get('/configuracion')->assertOk()->assertSee('{saldo}');
});
