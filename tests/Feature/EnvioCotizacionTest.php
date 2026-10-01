<?php

use App\Enums\EstadoCotizacion;
use App\Mail\CotizacionMail;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->cotizacion = Cotizacion::factory()
        ->for(Cliente::factory()->for($this->user)->create(['correo' => 'cliente@ejemplo.mx']))
        ->conLinea()
        ->create(['user_id' => $this->user->id]);
});

it('envía el correo con el PDF adjunto y marca la cotización como enviada', function () {
    Mail::fake();

    $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/enviar", [
        'destinatarios_texto' => 'cliente@ejemplo.mx, compras@ejemplo.mx',
    ])->assertRedirect(route('cotizaciones.show', $this->cotizacion))->assertSessionHas('exito');

    Mail::assertSent(CotizacionMail::class, fn (CotizacionMail $correo) => $correo->hasTo('cliente@ejemplo.mx')
        && $correo->hasTo('compras@ejemplo.mx')
        && $correo->cotizacion->is($this->cotizacion));

    expect($this->cotizacion->fresh()->estado)->toBe(EstadoCotizacion::Enviada);
});

it('regresa a la bandeja con la misma cotización abierta cuando se envía desde ahí', function () {
    Mail::fake();

    $bandeja = route('cotizaciones.index', ['estado' => 'borrador', 'cotizacion' => $this->cotizacion->id]);

    $this->actingAs($this->user)->from($bandeja)->post("/cotizaciones/{$this->cotizacion->id}/enviar", [
        'destinatarios_texto' => 'cliente@ejemplo.mx',
        'origen' => 'bandeja',
    ])->assertRedirect($bandeja)->assertSessionHas('exito');

    expect($this->cotizacion->fresh()->estado)->toBe(EstadoCotizacion::Enviada);
});

it('no regresa a una página ajena a la bandeja', function (string $anterior) {
    Mail::fake();

    $this->actingAs($this->user)->from($anterior)->post("/cotizaciones/{$this->cotizacion->id}/enviar", [
        'destinatarios_texto' => 'cliente@ejemplo.mx',
        'origen' => 'bandeja',
    ])->assertRedirect(route('cotizaciones.index', ['cotizacion' => $this->cotizacion->id]));
})->with(['https://otro-sitio.example/cotizaciones?x=1', 'http://ticket_factura.test/clientes']);

it('adjunta el PDF generado al vuelo', function () {
    $correo = new CotizacionMail($this->cotizacion);
    $adjunto = $correo->attachments()[0];

    expect($adjunto->as)->toBe('cotizacion-COT-0001.pdf')
        ->and($adjunto->mime)->toBe('application/pdf');

    $correo->assertSeeInHtml('COT-0001')->assertSeeInHtml('$116.00');
});

it('rechaza destinatarios inválidos sin cambiar el estado', function (string $texto) {
    Mail::fake();

    $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/enviar", ['destinatarios_texto' => $texto])
        ->assertSessionHasErrorsIn('envio');

    Mail::assertNothingSent();
    expect($this->cotizacion->fresh()->estado)->toBe(EstadoCotizacion::Borrador);
})->with(['', 'no-es-correo', 'a@b.mx,b@b.mx,c@b.mx,d@b.mx,e@b.mx,f@b.mx']);

it('si el correo falla no cambia el estado', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP caído'));

    $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/enviar", ['destinatarios_texto' => 'a@b.mx'])
        ->assertSessionHasErrorsIn('envio', 'destinatarios');

    expect($this->cotizacion->fresh()->estado)->toBe(EstadoCotizacion::Borrador);
});

it('reenviar una enviada reinicia el plazo de caducidad', function () {
    Mail::fake();
    $this->cotizacion->forceFill(['estado' => EstadoCotizacion::Enviada])->save();
    $this->travel(10)->days();

    $this->actingAs($this->user)->post("/cotizaciones/{$this->cotizacion->id}/enviar", ['destinatarios_texto' => 'a@b.mx']);

    expect($this->cotizacion->fresh()->updated_at->isToday())->toBeTrue();
});

it('marca como enviada después de compartir, sin degradar una pagada', function () {
    $this->actingAs($this->user)->postJson("/cotizaciones/{$this->cotizacion->id}/marcar-enviada")
        ->assertOk()
        ->assertExactJson(['estado' => 'enviada', 'etiqueta' => 'Enviada']);

    $this->cotizacion->forceFill(['estado' => EstadoCotizacion::Pagada])->save();

    $this->actingAs($this->user)->postJson("/cotizaciones/{$this->cotizacion->id}/marcar-enviada")
        ->assertJsonPath('estado', 'pagada');
});
