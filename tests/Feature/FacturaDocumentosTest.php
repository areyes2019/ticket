<?php

use App\Enums\EstadoCancelacion;
use App\Enums\EstadoComplementoPago;
use App\Enums\EstadoFactura;
use App\Enums\MotivoCancelacion;
use App\Enums\TipoErrorTimbrado;
use App\Mail\FacturaMail;
use App\Models\Cliente;
use App\Models\Factura;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config(['services.facturapi.llave' => 'sk_test_prueba']);

    $this->user = User::factory()->create();
    $this->cliente = Cliente::factory()->for($this->user)->create(['correo' => 'cliente@ejemplo.mx']);
    $this->factura = Factura::factory()->for($this->cliente)->timbrada()->conLinea()->create(['user_id' => $this->user->id]);
});

describe('cancelación', function () {
    it('pasa a cancelada cuando facturapi.io la acepta', function () {
        Http::fake(['www.facturapi.io/v2/invoices/*' => Http::response(['status' => 'canceled', 'cancellation_status' => 'accepted'])]);

        $this->actingAs($this->user)->post("/facturas/{$this->factura->id}/cancelar", ['motivo_cancelacion' => '02'])
            ->assertRedirect(route('facturas.show', $this->factura))
            ->assertSessionHas('exito', 'Factura cancelada.');

        expect($this->factura->fresh())
            ->estado->toBe(EstadoFactura::Cancelada)
            ->estado_cancelacion->toBe(EstadoCancelacion::Aceptada)
            ->motivo_cancelacion->toBe(MotivoCancelacion::ErroresSinRelacion)
            ->fecha_cancelacion->not->toBeNull();

        Http::assertSent(fn (Request $peticion) => $peticion->method() === 'DELETE'
            && str_ends_with($peticion->url(), "/invoices/{$this->factura->facturapi_invoice_id}?motive=02"));
    });

    it('queda timbrada con la cancelación en proceso y se refresca al abrir el detalle', function () {
        Http::fakeSequence('www.facturapi.io/v2/invoices/*')
            ->push(['status' => 'valid', 'cancellation_status' => 'pending'])
            ->push(['status' => 'canceled', 'cancellation_status' => 'accepted']);

        $this->actingAs($this->user)->post("/facturas/{$this->factura->id}/cancelar", ['motivo_cancelacion' => '03']);

        expect($this->factura->fresh())
            ->estado->toBe(EstadoFactura::Timbrada)
            ->estado_cancelacion->toBe(EstadoCancelacion::Pendiente);

        $this->actingAs($this->user)->get("/facturas/{$this->factura->id}")->assertOk();

        expect($this->factura->fresh()->estado)->toBe(EstadoFactura::Cancelada);
    });

    it('si la consulta del estado falla el detalle se muestra igual', function () {
        $this->factura->forceFill(['estado_cancelacion' => EstadoCancelacion::Verificando])->save();
        Http::fake(['www.facturapi.io/v2/invoices/*' => Http::response(['message' => 'Error interno'], 500)]);

        $this->actingAs($this->user)->get("/facturas/{$this->factura->id}")
            ->assertOk()
            ->assertSee('No se pudo consultar el estado de la cancelación');

        expect($this->factura->fresh()->estado_cancelacion)->toBe(EstadoCancelacion::Verificando);
    });

    it('con motivo 01 manda el UUID de la sustituta', function () {
        Http::fake(['www.facturapi.io/v2/invoices/*' => Http::response(['status' => 'canceled', 'cancellation_status' => 'accepted'])]);
        $sustituta = Factura::factory()->for($this->cliente)->timbrada()->conLinea()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->post("/facturas/{$this->factura->id}/cancelar", [
            'motivo_cancelacion' => '01',
            'factura_sustituta_id' => $sustituta->id,
        ])->assertSessionHasNoErrors();

        expect($this->factura->fresh()->factura_sustituta_id)->toBe($sustituta->id);
        Http::assertSent(fn (Request $peticion) => str_contains($peticion->url(), 'substitution='.$sustituta->uuid_fiscal));
    });

    it('con motivo 01 exige una sustituta propia, timbrada y distinta', function (string $caso) {
        Http::fake();

        $sustituta = match ($caso) {
            'sin sustituta' => null,
            'ella misma' => $this->factura->id,
            'ajena' => Factura::factory()->timbrada()->conLinea()->create()->id,
            'pendiente' => Factura::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id])->id,
        };

        $this->actingAs($this->user)->post("/facturas/{$this->factura->id}/cancelar", [
            'motivo_cancelacion' => '01',
            'factura_sustituta_id' => $sustituta,
        ])->assertSessionHasErrorsIn('cancelacion', 'factura_sustituta_id');

        Http::assertNothingSent();
    })->with(['sin sustituta', 'ella misma', 'ajena', 'pendiente']);

    it('muestra el error de facturapi.io sin cambiar el estado', function () {
        Http::fake(['www.facturapi.io/v2/invoices/*' => Http::response(['message' => 'La factura no se puede cancelar.'], 400)]);

        $this->actingAs($this->user)->post("/facturas/{$this->factura->id}/cancelar", ['motivo_cancelacion' => '02'])
            ->assertSessionHasErrorsIn('cancelacion', 'motivo_cancelacion');

        expect($this->factura->fresh())
            ->estado->toBe(EstadoFactura::Timbrada)
            ->estado_cancelacion->toBeNull();
    });
});

describe('XML y PDF', function () {
    it('descarga el XML en vivo desde facturapi.io', function () {
        Http::fake(['www.facturapi.io/v2/invoices/*/xml' => Http::response('<cfdi:Comprobante Version="4.0"/>', 200, ['Content-Type' => 'application/xml'])]);

        $this->actingAs($this->user)->get("/facturas/{$this->factura->id}/xml")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->assertDownload('factura-'.$this->factura->folioFiscal().'.xml');
    });

    it('avisa si facturapi.io no entrega el XML', function () {
        Http::fake(['www.facturapi.io/v2/invoices/*/xml' => Http::response(['message' => 'Error'], 502)]);

        $this->actingAs($this->user)->get("/facturas/{$this->factura->id}/xml")
            ->assertRedirect(route('facturas.show', $this->factura))
            ->assertSessionHas('error', 'No se pudo obtener el XML de facturapi.io. Intenta de nuevo.');
    });

    it('genera el PDF al vuelo sin llamar a facturapi.io', function () {
        Http::fake();

        $respuesta = $this->actingAs($this->user)->get("/facturas/{$this->factura->id}/pdf?descargar=1")->assertOk();

        expect($respuesta->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($respuesta->headers->get('Content-Disposition'))->toContain('factura-'.$this->factura->folioFiscal().'.pdf');
        Http::assertNothingSent();
    });

    it('no hay XML ni PDF de una factura pendiente', function (string $documento) {
        Http::fake();
        $pendiente = Factura::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);
        $pendiente->registrarErrorTimbrado('Error', TipoErrorTimbrado::Pac);

        $this->actingAs($this->user)->get("/facturas/{$pendiente->id}/{$documento}")->assertSessionHas('error');
        Http::assertNothingSent();
    })->with(['xml', 'pdf']);
});

describe('correo', function () {
    it('envía el XML y el PDF a los destinatarios', function () {
        Mail::fake();
        Http::fake(['www.facturapi.io/v2/invoices/*/xml' => Http::response('<cfdi:Comprobante/>')]);

        $this->actingAs($this->user)->post("/facturas/{$this->factura->id}/enviar", ['destinatarios_texto' => 'cliente@ejemplo.mx, contador@ejemplo.mx'])
            ->assertRedirect(route('facturas.show', $this->factura))
            ->assertSessionHas('exito');

        Mail::assertSent(FacturaMail::class, fn (FacturaMail $correo) => $correo->hasTo('cliente@ejemplo.mx')
            && $correo->hasTo('contador@ejemplo.mx')
            && $correo->xml === '<cfdi:Comprobante/>');

        expect($this->factura->fresh()->estado)->toBe(EstadoFactura::Timbrada);
    });

    it('adjunta XML y PDF con el folio fiscal', function () {
        $correo = new FacturaMail($this->factura, '<cfdi:Comprobante/>');
        $adjuntos = $correo->attachments();

        expect($adjuntos)->toHaveCount(2)
            ->and($adjuntos[0]->as)->toBe('factura-'.$this->factura->folioFiscal().'.xml')
            ->and($adjuntos[1]->as)->toBe('factura-'.$this->factura->folioFiscal().'.pdf')
            ->and($adjuntos[1]->mime)->toBe('application/pdf');

        $correo->assertSeeInHtml($this->factura->uuid_fiscal)->assertSeeInHtml('$116.00');
    });

    it('si el XML no llega no envía nada', function () {
        Mail::fake();
        Http::fake(['www.facturapi.io/v2/invoices/*/xml' => Http::response(['message' => 'Error'], 500)]);

        $this->actingAs($this->user)->post("/facturas/{$this->factura->id}/enviar", ['destinatarios_texto' => 'a@b.mx'])
            ->assertSessionHasErrorsIn('envio', 'destinatarios');

        Mail::assertNothingSent();
    });

    it('no envía una factura cancelada', function () {
        Mail::fake();
        Http::fake();
        $this->factura->forceFill(['estado' => EstadoFactura::Cancelada])->save();

        $this->actingAs($this->user)->post("/facturas/{$this->factura->id}/enviar", ['destinatarios_texto' => 'a@b.mx'])
            ->assertSessionHas('error');

        Mail::assertNothingSent();
    });

    it('prellena el correo del cliente y ofrece compartir solo el PDF', function () {
        $this->actingAs($this->user)->get("/facturas/{$this->factura->id}")
            ->assertOk()
            ->assertSee('value="cliente@ejemplo.mx"', false)
            ->assertSee('data-compartir-pdf', false)
            ->assertSee('data-precargar="al-cargar"', false)
            ->assertDontSee('data-texto', false)
            ->assertSee('Por aquí va el PDF; el XML se manda por correo.');
    });
});

describe('complemento de pago', function () {
    beforeEach(function () {
        $this->ppd = Factura::factory()->for($this->cliente)->ppd()->timbrada()->conLinea()->create(['user_id' => $this->user->id]);
    });

    it('timbra el complemento con el payload real', function () {
        Http::fake(['www.facturapi.io/v2/invoices' => Http::response(respuestaTimbrado(['id' => 'complemento-1', 'uuid' => 'C0C0C0C0-0000-0000-0000-000000000000']))]);

        $this->actingAs($this->user)->post("/facturas/{$this->ppd->id}/complemento-pago", [
            'fecha_pago' => now(config('app.zona_negocio'))->toDateString(),
            'monto' => '58.00',
            'forma_pago' => '03',
        ])->assertRedirect(route('facturas.show', $this->ppd))->assertSessionHas('exito');

        $complemento = $this->ppd->complementoPago()->sole();
        expect($complemento->estado)->toBe(EstadoComplementoPago::Timbrado)
            ->and($complemento->uuid_fiscal)->toBe('C0C0C0C0-0000-0000-0000-000000000000')
            ->and($complemento->cadena_original_sat)->toStartWith('||1.1|');

        Http::assertSent(function (Request $peticion) {
            $documento = $peticion['complements'][0]['data']['related_documents'][0];

            return $peticion['type'] === 'P'
                && $peticion['complements'][0]['type'] === 'pago'
                && $documento['uuid'] === $this->ppd->uuid_fiscal
                && $documento['last_balance'] === 116.0
                && $documento['amount'] === 58.0
                && $documento['taxes'] === [['type' => 'IVA', 'rate' => 0.16, 'base' => 50.0]];
        });
    });

    it('rechaza una factura PUE y un monto mayor al total', function () {
        Http::fake();

        $this->actingAs($this->user)->post("/facturas/{$this->factura->id}/complemento-pago", [
            'fecha_pago' => now(config('app.zona_negocio'))->toDateString(), 'monto' => '10.00', 'forma_pago' => '03',
        ])->assertSessionHas('error');

        $this->actingAs($this->user)->post("/facturas/{$this->ppd->id}/complemento-pago", [
            'fecha_pago' => now(config('app.zona_negocio'))->toDateString(), 'monto' => '116.01', 'forma_pago' => '03',
        ])->assertSessionHasErrorsIn('complemento', 'monto');

        Http::assertNothingSent();
    });

    it('permite reintentar un complemento fallido pero no un segundo timbrado', function () {
        Http::fakeSequence('www.facturapi.io/v2/invoices')
            ->push(['message' => 'Datos del pago inválidos'], 400)
            ->push(respuestaTimbrado(['id' => 'complemento-2']));

        $datos = ['fecha_pago' => now(config('app.zona_negocio'))->toDateString(), 'monto' => '116.00', 'forma_pago' => '03'];

        $this->actingAs($this->user)->post("/facturas/{$this->ppd->id}/complemento-pago", $datos)->assertSessionHas('error');
        expect($this->ppd->complementoPago()->sole()->estado)->toBe(EstadoComplementoPago::Error);

        $this->actingAs($this->user)->post("/facturas/{$this->ppd->id}/complemento-pago", $datos)->assertSessionHas('exito');
        expect($this->ppd->complementoPago()->sole()->estado)->toBe(EstadoComplementoPago::Timbrado);

        $this->actingAs($this->user)->post("/facturas/{$this->ppd->id}/complemento-pago", $datos)->assertSessionHas('error');
        Http::assertSentCount(2);
    });
});
