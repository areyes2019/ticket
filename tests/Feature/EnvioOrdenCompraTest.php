<?php

use App\Enums\EstadoOrdenCompra;
use App\Mail\OrdenCompraMail;
use App\Models\OrdenCompra;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->orden = OrdenCompra::factory()
        ->for(Proveedor::factory()->for($this->user)->create(['correo' => 'ventas@proveedor.mx']))
        ->conLinea()
        ->create();
});

it('envía el correo con el PDF adjunto y marca la orden como enviada', function () {
    Mail::fake();

    $this->actingAs($this->user)->post("/ordenes-compra/{$this->orden->id}/enviar", [
        'destinatarios_texto' => 'ventas@proveedor.mx, compras@proveedor.mx',
    ])->assertRedirect(route('ordenes-compra.show', $this->orden))->assertSessionHas('exito');

    Mail::assertSent(OrdenCompraMail::class, fn (OrdenCompraMail $correo) => $correo->hasTo('ventas@proveedor.mx')
        && $correo->hasTo('compras@proveedor.mx')
        && $correo->hasSubject('Orden de compra OC-0001 — '.config('app.name'))
        && $correo->orden->is($this->orden));

    expect($this->orden->fresh()->estado)->toBe(EstadoOrdenCompra::Enviada);
});

it('adjunta el PDF generado al vuelo', function () {
    $adjunto = (new OrdenCompraMail($this->orden))->attachments()[0];

    expect($adjunto->as)->toBe('orden-compra-OC-0001.pdf')
        ->and($adjunto->mime)->toBe('application/pdf');
});

it('reenviar una orden pagada no cambia su estado', function () {
    Mail::fake();
    $this->orden->forceFill(['estado' => EstadoOrdenCompra::Pagada])->save();

    $this->actingAs($this->user)->post("/ordenes-compra/{$this->orden->id}/enviar", ['destinatarios_texto' => 'ventas@proveedor.mx']);

    expect($this->orden->fresh()->estado)->toBe(EstadoOrdenCompra::Pagada);
});

it('rechaza destinatarios inválidos en la bolsa del envío', function () {
    Mail::fake();

    $this->actingAs($this->user)->post("/ordenes-compra/{$this->orden->id}/enviar", ['destinatarios_texto' => 'no-es-correo'])
        ->assertSessionHasErrorsIn('envio', 'destinatarios.0');

    Mail::assertNothingSent();
    expect($this->orden->fresh()->estado)->toBe(EstadoOrdenCompra::Borrador);
});

it('marca como enviada después de compartir y responde el estado', function () {
    $this->actingAs($this->user)->postJson("/ordenes-compra/{$this->orden->id}/marcar-enviada")
        ->assertOk()
        ->assertExactJson(['estado' => 'enviada', 'etiqueta' => 'Enviada']);

    expect($this->orden->fresh()->estado)->toBe(EstadoOrdenCompra::Enviada);
});

it('no envía órdenes ajenas', function () {
    Mail::fake();
    $ajena = OrdenCompra::factory()->conLinea()->create();

    $this->actingAs($this->user)->post("/ordenes-compra/{$ajena->id}/enviar", ['destinatarios_texto' => 'a@b.mx'])->assertNotFound();
    $this->actingAs($this->user)->postJson("/ordenes-compra/{$ajena->id}/marcar-enviada")->assertNotFound();

    Mail::assertNothingSent();
});
