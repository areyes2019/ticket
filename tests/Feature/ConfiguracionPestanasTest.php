<?php

use App\Models\Emisor;
use App\Models\User;
use App\Models\WhatsappNumero;

/**
 * Configuración en pestañas (042, adición 4).
 */
beforeEach(function () {
    $this->admin = User::factory()->administrador()->create();
    $this->user = User::factory()->create();
});

function conectarWhatsapp(User $user): void
{
    $numero = new WhatsappNumero([
        'phone_number_id' => '1111111111',
        'waba_id' => '2222222222',
        'token' => 'token-meta',
        'app_secret' => 'secreto-app',
        'verify_token' => 'verificacion-123456',
    ]);
    $numero->user_id = $user->id;
    $numero->save();
}

it('el administrador ve las cuatro pestañas en orden con sus secciones', function () {
    $this->actingAs($this->admin)->get('/configuracion')
        ->assertOk()
        ->assertSee('data-pestanas-barra', false)
        ->assertSeeInOrder(['href="#emisor"', 'href="#datos-bancarios"', 'href="#whatsapp"', 'href="#mensajes"'], false)
        ->assertSeeInOrder(['Emisor', 'Datos bancarios', 'WhatsApp', 'Mensajes de pedidos'])
        ->assertSee('id="emisor"', false)
        ->assertSee('id="datos-bancarios"', false)
        ->assertSee('id="whatsapp"', false)
        ->assertSee('id="mensajes"', false);
});

it('el usuario normal solo ve los mensajes, sin barra de pestañas', function () {
    $this->actingAs($this->user)->get('/configuracion')
        ->assertOk()
        ->assertSee('id="mensajes"', false)
        ->assertDontSee('data-pestanas-barra', false)
        ->assertDontSee('id="emisor"', false)
        ->assertDontSee('id="datos-bancarios"', false)
        ->assertDontSee('id="whatsapp"', false);
});

it('al guardar regresa a la pestaña de la sección', function () {
    $this->actingAs($this->user)->put('/configuracion', ['mensaje_ticket' => 'Hola', 'mensaje_listo' => 'Listo'])
        ->assertRedirect(route('configuracion.edit').'#mensajes');

    $this->actingAs($this->admin)->put('/configuracion/emisor', [
        'nombre' => 'SELLO PRONTO DISTRIBUCIONES', 'rfc' => 'EKU9003173C9', 'regimen_fiscal' => '612',
    ])->assertRedirect(route('configuracion.edit').'#emisor');

    $this->actingAs($this->admin)->put('/configuracion/whatsapp', [
        'phone_number_id' => '1111111111', 'waba_id' => '3333333333', 'token' => 'x', 'app_secret' => 'y',
        'verify_token' => 'verificacion-abcdef', 'tope_mensual_usd' => 50, 'limite_hora' => 20, 'limite_dia' => 100,
    ])->assertRedirect(route('configuracion.edit').'#whatsapp');

    $this->actingAs($this->admin)->post('/configuracion/datos-bancarios', [
        'nombre_banco' => 'BBVA', 'clabe' => '012180001234567899', 'visible_en_cotizaciones' => '1',
    ])->assertRedirect(route('configuracion.edit').'#datos-bancarios');
});

it('las pestañas de Emisor y WhatsApp avisan cuando falta algo', function () {
    $this->actingAs($this->admin)->get('/configuracion')
        ->assertSee('title="Faltan datos fiscales"', false)
        ->assertSee('title="Sin conectar"', false);

    Emisor::create(['nombre' => 'SELLO PRONTO DISTRIBUCIONES', 'rfc' => 'EKU9003173C9', 'regimen_fiscal' => '612']);
    conectarWhatsapp($this->admin);

    $this->actingAs($this->admin->fresh())->get('/configuracion')
        ->assertOk()
        ->assertDontSee('pestana-aviso', false);
});
