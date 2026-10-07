<?php

use App\Mail\CotizacionMail;
use App\Mail\FacturaMail;
use App\Mail\OrdenCompraMail;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Factura;
use App\Models\OrdenCompra;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config(['negocio.copia_correos' => 'ventas@sellopronto.com.mx', 'services.facturapi.llave' => 'sk_test_prueba']);
    $this->user = User::factory()->create();
    $this->cliente = Cliente::factory()->for($this->user)->create(['correo' => 'cliente@ejemplo.mx']);
    Mail::fake();
});

it('la cotización enviada llega con copia oculta al negocio', function () {
    $cotizacion = Cotizacion::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)->post("/cotizaciones/{$cotizacion->id}/enviar", ['destinatarios_texto' => 'cliente@ejemplo.mx'])
        ->assertSessionHas('exito');

    Mail::assertSent(CotizacionMail::class, fn (CotizacionMail $correo) => $correo->hasTo('cliente@ejemplo.mx')
        && $correo->hasBcc('ventas@sellopronto.com.mx')
        && ! $correo->hasCc('ventas@sellopronto.com.mx'));
});

it('la orden de compra enviada llega con copia oculta al negocio', function () {
    $orden = OrdenCompra::factory()->for(Proveedor::factory()->for($this->user)->create())->conLinea()->create();

    $this->actingAs($this->user)->post("/ordenes-compra/{$orden->id}/enviar", ['destinatarios_texto' => 'ventas@proveedor.mx'])
        ->assertSessionHas('exito');

    Mail::assertSent(OrdenCompraMail::class, fn (OrdenCompraMail $correo) => $correo->hasTo('ventas@proveedor.mx')
        && $correo->hasBcc('ventas@sellopronto.com.mx'));
});

it('la factura enviada llega con copia oculta al negocio', function () {
    Http::fake(['www.facturapi.io/v2/invoices/*/xml' => Http::response('<cfdi:Comprobante/>')]);
    $factura = Factura::factory()->for($this->cliente)->timbrada()->conLinea()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)->post("/facturas/{$factura->id}/enviar", ['destinatarios_texto' => 'cliente@ejemplo.mx'])
        ->assertSessionHas('exito');

    Mail::assertSent(FacturaMail::class, fn (FacturaMail $correo) => $correo->hasTo('cliente@ejemplo.mx')
        && $correo->hasBcc('ventas@sellopronto.com.mx'));
});

it('no duplica la copia si el negocio ya va entre los destinatarios', function () {
    $cotizacion = Cotizacion::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)->post("/cotizaciones/{$cotizacion->id}/enviar", ['destinatarios_texto' => 'cliente@ejemplo.mx, Ventas@SelloPronto.com.mx'])
        ->assertSessionHas('exito');

    Mail::assertSent(CotizacionMail::class, fn (CotizacionMail $correo) => ! $correo->hasBcc('ventas@sellopronto.com.mx'));
});

it('sin correo de copia configurado no manda copia', function () {
    config(['negocio.copia_correos' => '']);
    $cotizacion = Cotizacion::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)->post("/cotizaciones/{$cotizacion->id}/enviar", ['destinatarios_texto' => 'cliente@ejemplo.mx'])
        ->assertSessionHas('exito');

    Mail::assertSent(CotizacionMail::class, fn (CotizacionMail $correo) => ! $correo->hasBcc('ventas@sellopronto.com.mx'));
});

it('el mensaje que sale al servidor de correo lleva la copia oculta y no la muestra al cliente', function () {
    $mailer = (new MailManager(app()))->mailer('array');
    $cotizacion = Cotizacion::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);

    $mailer->to('cliente@ejemplo.mx')->send(new CotizacionMail($cotizacion));

    $mensaje = $mailer->getSymfonyTransport()->messages()->last()->getOriginalMessage();

    expect(collect($mensaje->getBcc())->map->getAddress()->all())->toBe(['ventas@sellopronto.com.mx'])
        ->and(collect($mensaje->getTo())->map->getAddress()->all())->toBe(['cliente@ejemplo.mx'])
        ->and($mensaje->getCc())->toBe([]);
});
