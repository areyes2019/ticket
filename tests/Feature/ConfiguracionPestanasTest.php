<?php

use App\Models\Emisor;
use App\Models\User;
use App\Models\WhatsappNumero;
use Illuminate\Support\Facades\DB;

/**
 * Configuración en pestañas (042, adición 4) y en tres pestañas: Negocio,
 * WhatsApp y Agentes IA (046, adición 4).
 */
beforeEach(function () {
    $this->admin = User::factory()->administrador()->create();
    $this->user = User::factory()->create();
});

function conectarWhatsapp(User $user, array $cambios = []): WhatsappNumero
{
    $numero = new WhatsappNumero([
        'phone_number_id' => '1111111111',
        'waba_id' => '2222222222',
        'token' => 'token-meta',
        'app_secret' => 'secreto-app',
        'verify_token' => 'verificacion-123456',
        ...$cambios,
    ]);
    $numero->user_id = $user->id;
    $numero->save();

    return $numero;
}

it('el administrador ve las tres pestañas en orden con todos sus bloques', function () {
    conectarWhatsapp($this->admin);

    $this->actingAs($this->admin)->get('/configuracion')
        ->assertOk()
        ->assertSee('data-pestanas-barra', false)
        ->assertSeeInOrder(['href="#negocio"', 'href="#whatsapp"', 'href="#agentes"'], false)
        ->assertSeeInOrder(['Negocio', 'WhatsApp', 'Agentes IA'])
        // Negocio: emisor, bancos, horario y mensajes, en ese orden (regla 4).
        ->assertSeeInOrder(['id="negocio"', 'id="emisor"', 'id="datos-bancarios"', 'id="horario"', 'id="mensajes"', 'id="whatsapp"', 'id="respuestas-rapidas"', 'id="agentes"'], false)
        ->assertSee('Guardar conexión')
        ->assertSee('Guardar agentes')
        ->assertSee('Guardar horario')
        ->assertSee('Guardar mensajes')
        ->assertDontSee('Guardar WhatsApp');
});

it('el usuario normal ve Negocio con mensajes y WhatsApp con respuestas rápidas', function () {
    $this->actingAs($this->user)->get('/configuracion')
        ->assertOk()
        ->assertSee('data-pestanas-barra', false)
        ->assertSee('href="#negocio"', false)
        ->assertSee('href="#whatsapp"', false)
        ->assertDontSee('href="#agentes"', false)
        ->assertSee('id="mensajes"', false)
        ->assertSee('id="respuestas-rapidas"', false)
        ->assertDontSee('id="emisor"', false)
        ->assertDontSee('id="datos-bancarios"', false)
        ->assertDontSee('id="horario"', false)
        ->assertDontSee('id="agentes"', false)
        ->assertDontSee('Guardar conexión')
        ->assertDontSee('pestana-aviso', false);
});

it('al guardar regresa a la pestaña y al bloque de lo guardado', function () {
    $this->actingAs($this->user)->put('/configuracion', ['mensaje_ticket' => 'Hola', 'mensaje_listo' => 'Listo'])
        ->assertRedirect(route('configuracion.edit').'#mensajes');

    $this->actingAs($this->admin)->put('/configuracion/emisor', [
        'nombre' => 'SELLO PRONTO DISTRIBUCIONES', 'rfc' => 'EKU9003173C9', 'regimen_fiscal' => '612',
    ])->assertRedirect(route('configuracion.edit').'#emisor');

    $this->actingAs($this->admin)->put('/configuracion/horario', ['horario' => []])
        ->assertRedirect(route('configuracion.edit').'#horario');

    $this->actingAs($this->admin)->put('/configuracion/whatsapp', [
        'phone_number_id' => '1111111111', 'waba_id' => '3333333333', 'token' => 'x', 'app_secret' => 'y',
        'verify_token' => 'verificacion-abcdef',
    ])->assertRedirect(route('configuracion.edit').'#whatsapp');

    $this->actingAs($this->admin->fresh())->put('/configuracion/whatsapp/agentes', [
        'tope_mensual_usd' => 50, 'limite_hora' => 20, 'limite_dia' => 100,
    ])->assertRedirect(route('configuracion.edit').'#agentes');

    $this->actingAs($this->admin)->post('/configuracion/datos-bancarios', [
        'nombre_banco' => 'BBVA', 'clabe' => '012180001234567899', 'visible_en_cotizaciones' => '1',
    ])->assertRedirect(route('configuracion.edit').'#datos-bancarios');
});

it('guardar la conexión no toca los agentes y guardar los agentes no toca la conexión', function () {
    $numero = conectarWhatsapp($this->admin, [
        'ventas_activo' => true, 'aviso_humano' => 'Ya te atiende alguien.', 'tope_mensual_usd' => 80, 'limite_hora' => 5,
        'indicaciones_ventas' => ['pago' => 'Transferencia'],
    ]);

    $this->actingAs($this->admin)->put('/configuracion/whatsapp', [
        'phone_number_id' => '1111111111', 'waba_id' => '9999999999', 'token' => '', 'app_secret' => '', 'verify_token' => 'verificacion-nueva',
    ])->assertSessionHas('exito', 'Conexión con Meta guardada.');

    $numero->refresh();
    expect($numero)->waba_id->toBe('9999999999')->token->toBe('token-meta')
        ->ventas_activo->toBeTrue()->aviso_humano->toBe('Ya te atiende alguien.')
        ->tope_mensual_usd->toBe('80.00')->limite_hora->toBe(5)
        ->and($numero->indicaciones_ventas['pago'])->toBe('Transferencia');

    $this->actingAs($this->admin->fresh())->put('/configuracion/whatsapp/agentes', [
        // Campos de la conexión colados: se ignoran.
        'phone_number_id' => '5555555555', 'token' => 'otro',
        'facturacion_activo' => '1', 'tope_mensual_usd' => 30, 'limite_hora' => 10, 'limite_dia' => 40,
    ])->assertSessionHas('exito', 'Ajustes de los agentes guardados.');

    $numero->refresh();
    expect($numero)->phone_number_id->toBe('1111111111')->token->toBe('token-meta')->verify_token->toBe('verificacion-nueva')
        ->ventas_activo->toBeFalse()->facturacion_activo->toBeTrue()
        ->tope_mensual_usd->toBe('30.00')->limite_hora->toBe(10)->limite_dia->toBe(40);
});

it('un número recién conectado toma los valores por defecto de los agentes', function () {
    $this->actingAs($this->admin)->put('/configuracion/whatsapp', [
        'phone_number_id' => '1111111111', 'waba_id' => '3333333333', 'token' => 'x', 'app_secret' => 'y', 'verify_token' => 'verificacion-abcdef',
    ])->assertSessionHas('exito');

    expect($this->admin->fresh()->whatsappNumero)
        ->ventas_activo->toBeFalse()->facturacion_activo->toBeFalse()
        ->tope_mensual_usd->toBe('50.00')->limite_hora->toBe(20)->limite_dia->toBe(100);
});

it('sin número, Agentes IA solo avisa y guardar agentes regresa a WhatsApp', function () {
    $this->actingAs($this->admin)->get('/configuracion')
        ->assertOk()
        ->assertSee('Primero conecta WhatsApp')
        ->assertDontSee('Guardar agentes');

    $this->actingAs($this->admin)->put('/configuracion/whatsapp/agentes', ['tope_mensual_usd' => 50, 'limite_hora' => 20, 'limite_dia' => 100])
        ->assertRedirect(route('configuracion.edit').'#whatsapp')
        ->assertSessionHas('error');

    expect(WhatsappNumero::count())->toBe(0);
});

it('solo el administrador guarda el horario, la conexión y los agentes', function () {
    conectarWhatsapp($this->user);

    $this->actingAs($this->user)->put('/configuracion/horario', ['horario' => []])->assertForbidden();
    $this->actingAs($this->user)->put('/configuracion/whatsapp/agentes', ['tope_mensual_usd' => 1, 'limite_hora' => 1, 'limite_dia' => 1])->assertForbidden();
});

it('el horario se guarda con el negocio, aunque no haya WhatsApp', function () {
    $this->actingAs($this->admin)->put('/configuracion/horario', ['horario' => [
        'lun' => ['abierto' => '1', 'abre' => '10:00', 'cierra' => '14:00'],
        'mar' => ['abierto' => '1', 'abre' => '15:00', 'cierra' => '14:00'],
        'sab' => ['abre' => '09:00', 'cierra' => '13:00'],
    ]])->assertSessionHas('exito', 'Horario de atención guardado.');

    $horario = Emisor::actual()->horarioSemanal();
    expect($horario['lun'])->toBe(['10:00', '14:00'])
        ->and($horario['mar'])->toBeNull()
        ->and($horario['sab'])->toBeNull()
        ->and(Emisor::actual()->horarioTexto())->toBe('lunes de 10:00 a.m. a 2:00 p.m.');

    // Guardar el emisor no borra el horario.
    $this->actingAs($this->admin)->put('/configuracion/emisor', ['nombre' => 'SELLO PRONTO', 'rfc' => 'EKU9003173C9', 'regimen_fiscal' => '612']);
    expect(Emisor::count())->toBe(1)
        ->and(Emisor::actual()->horarioSemanal()['lun'])->toBe(['10:00', '14:00']);
});

it('la migración pasa el horario del número al negocio', function () {
    $migracion = require database_path('migrations/2026_11_25_100000_horario_del_negocio.php');
    $horario = ['lun' => ['08:00', '12:00'], 'mar' => null, 'mie' => null, 'jue' => null, 'vie' => null, 'sab' => null, 'dom' => null];

    $migracion->down();
    $numero = conectarWhatsapp($this->admin);
    DB::table('whatsapp_numeros')->where('id', $numero->id)->update(['horario' => json_encode($horario)]);

    $migracion->up();

    expect(Emisor::count())->toBe(1)
        ->and(Emisor::actual()->horario)->toBe($horario);
});

it('la pestaña Negocio y la de WhatsApp avisan cuando falta algo', function () {
    $this->actingAs($this->admin)->get('/configuracion')
        ->assertSee('title="Faltan datos fiscales"', false)
        ->assertSee('title="Sin conectar"', false);

    Emisor::create(['nombre' => 'SELLO PRONTO DISTRIBUCIONES', 'rfc' => 'EKU9003173C9', 'regimen_fiscal' => '612']);
    conectarWhatsapp($this->admin);

    $this->actingAs($this->admin->fresh())->get('/configuracion')
        ->assertOk()
        ->assertDontSee('pestana-aviso', false);
});

it('ningún elemento de la página repite el id de una pestaña o de un bloque', function () {
    conectarWhatsapp($this->admin);
    $html = $this->actingAs($this->admin)->get('/configuracion')->getContent();

    foreach (['negocio', 'whatsapp', 'agentes', 'emisor', 'datos-bancarios', 'horario', 'mensajes', 'respuestas-rapidas'] as $ancla) {
        expect(substr_count($html, 'id="'.$ancla.'"'))->toBe(1);
    }
});
