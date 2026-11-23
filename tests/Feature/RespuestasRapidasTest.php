<?php

use App\Models\Cliente;
use App\Models\RespuestaRapida;
use App\Models\User;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappNumero;

/**
 * Respuestas rápidas del chat de WhatsApp (043, adición 4).
 */
beforeEach(function () {
    $this->admin = User::factory()->administrador()->create();
    $this->user = User::factory()->create();
});

function chatConRespuestasRapidas(User $user, array $cambios = []): WhatsappConversacion
{
    $numero = WhatsappNumero::query()->where('user_id', $user->id)->first();

    if ($numero === null) {
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

    $conversacion = new WhatsappConversacion;
    $conversacion->forceFill([
        'whatsapp_numero_id' => $numero->id,
        'user_id' => $user->id,
        'wa_id' => '5214491234567',
        'telefono_10' => '4491234567',
        'nombre_perfil' => 'Juanito del perfil',
        'ultimo_mensaje_cliente_en' => now(),
        'ultimo_mensaje_en' => now(),
        ...$cambios,
    ])->save();

    return $conversacion;
}

/**
 * El bloque JSON que la conversación manda al navegador (adición 1).
 *
 * @return array{respuestas: list<array{atajo: string, texto: string}>, nombre: string, fecha: string}
 */
function bloqueRespuestasRapidas(string $html): array
{
    preg_match('#<script type="application/json" id="wa-respuestas-rapidas"[^>]*>(.*?)</script>#s', $html, $m);
    expect($m)->not->toBeEmpty();

    return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
}

// --- Atajo y texto (reglas 2 y 3) ---

it('no deja repetir un atajo, aunque cambien mayúsculas o acentos', function (string $atajo) {
    RespuestaRapida::factory()->create(['atajo' => 'horario']);

    $this->actingAs($this->user)->post('/configuracion/respuestas-rapidas', ['atajo' => $atajo, 'texto' => 'Otro'])
        ->assertSessionHasErrors(['atajo' => 'Ya existe una respuesta con el atajo /horario.']);

    expect(RespuestaRapida::count())->toBe(1);
})->with(['horario', 'Horario', 'horário', '/HORARIO']);

it('editar una respuesta sin cambiar su atajo sí se permite', function () {
    $respuesta = RespuestaRapida::factory()->create(['atajo' => 'horario', 'texto' => 'Viejo']);

    $this->actingAs($this->user)->put("/configuracion/respuestas-rapidas/{$respuesta->id}", ['atajo' => 'horario', 'texto' => 'Nuevo'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('configuracion.edit').'#respuestas-rapidas');

    expect($respuesta->fresh()->texto)->toBe('Nuevo');
});

it('guarda el atajo en minúsculas, sin acentos y sin la diagonal', function () {
    $this->actingAs($this->user)->post('/configuracion/respuestas-rapidas', ['atajo' => ' /Horário ', 'texto' => "Lunes a viernes\nde 9 a 6 🙂"])
        ->assertSessionHasNoErrors();

    expect(RespuestaRapida::sole())->atajo->toBe('horario')->texto->toBe("Lunes a viernes\nde 9 a 6 🙂");
});

it('rechaza un atajo con espacios o con signos', function (string $atajo, string $mensaje) {
    $this->actingAs($this->user)->post('/configuracion/respuestas-rapidas', ['atajo' => $atajo, 'texto' => 'Hola'])
        ->assertSessionHasErrors(['atajo' => $mensaje]);
})->with([
    ['mi horario', 'El atajo debe ser una sola palabra, sin espacios.'],
    ['hola!', 'El atajo solo puede tener letras, números, - y _.'],
]);

it('rechaza un texto de más de 4000 caracteres y uno vacío', function () {
    $this->actingAs($this->user)->post('/configuracion/respuestas-rapidas', ['atajo' => 'largo', 'texto' => str_repeat('a', 4001)])
        ->assertSessionHasErrors('texto');
    $this->actingAs($this->user)->post('/configuracion/respuestas-rapidas', ['atajo' => 'vacio', 'texto' => ''])
        ->assertSessionHasErrors('texto');

    expect(RespuestaRapida::count())->toBe(0);
});

// --- Permisos (regla 19) ---

it('un usuario que no es administrador crea, edita y borra', function () {
    $this->actingAs($this->user)->get('/configuracion/respuestas-rapidas/crear')->assertOk();
    $this->actingAs($this->user)->post('/configuracion/respuestas-rapidas', ['atajo' => 'pago', 'texto' => 'Transferencia'])
        ->assertRedirect(route('configuracion.edit').'#respuestas-rapidas')
        ->assertSessionHas('exito', 'Respuesta rápida guardada.');

    $respuesta = RespuestaRapida::sole();
    $this->actingAs($this->user)->get("/configuracion/respuestas-rapidas/{$respuesta->id}/editar")->assertOk()->assertSee('Transferencia');
    $this->actingAs($this->user)->put("/configuracion/respuestas-rapidas/{$respuesta->id}", ['atajo' => 'pago', 'texto' => 'Depósito'])
        ->assertRedirect(route('configuracion.edit').'#respuestas-rapidas');

    $this->actingAs($this->user)->delete("/configuracion/respuestas-rapidas/{$respuesta->id}")
        ->assertRedirect(route('configuracion.edit').'#respuestas-rapidas')
        ->assertSessionHas('exito', 'Respuesta rápida eliminada.');

    expect(RespuestaRapida::count())->toBe(0);
});

it('sin sesión todas las rutas mandan al inicio de sesión', function () {
    $respuesta = RespuestaRapida::factory()->create();

    $this->get('/configuracion/respuestas-rapidas/crear')->assertRedirect(route('login'));
    $this->post('/configuracion/respuestas-rapidas', ['atajo' => 'x', 'texto' => 'x'])->assertRedirect(route('login'));
    $this->get("/configuracion/respuestas-rapidas/{$respuesta->id}/editar")->assertRedirect(route('login'));
    $this->put("/configuracion/respuestas-rapidas/{$respuesta->id}", ['atajo' => 'x', 'texto' => 'x'])->assertRedirect(route('login'));
    $this->delete("/configuracion/respuestas-rapidas/{$respuesta->id}")->assertRedirect(route('login'));

    expect(RespuestaRapida::count())->toBe(1);
});

// --- Pestaña en Configuración (reglas 15 y 16) ---

it('la pestaña sale para el administrador y para el usuario normal, en su lugar', function () {
    RespuestaRapida::factory()->create(['atajo' => 'pago', 'texto' => 'Transferencia a BBVA']);
    RespuestaRapida::factory()->create(['atajo' => 'horario', 'texto' => 'Lunes a viernes']);

    $this->actingAs($this->admin)->get('/configuracion')
        ->assertOk()
        ->assertSeeInOrder(['href="#emisor"', 'href="#datos-bancarios"', 'href="#whatsapp"', 'href="#respuestas-rapidas"', 'href="#mensajes"'], false)
        ->assertSeeInOrder(['/horario', '/pago']);

    $this->actingAs($this->user)->get('/configuracion')
        ->assertOk()
        ->assertSee('data-pestanas-barra', false)
        ->assertSeeInOrder(['href="#respuestas-rapidas"', 'href="#mensajes"'], false)
        ->assertSee('id="respuestas-rapidas"', false)
        ->assertSee('Lunes a viernes');
});

it('sin respuestas la pestaña invita a agregar la primera', function () {
    $this->actingAs($this->user)->get('/configuracion')
        ->assertSee('Todavía no hay respuestas rápidas.');
});

// --- En el chat (reglas 4 y 13, adición 1) ---

it('la conversación trae las respuestas en orden y el nombre del cliente', function () {
    RespuestaRapida::factory()->create(['atajo' => 'pago', 'texto' => 'Hola {nombre}, paga a BBVA']);
    RespuestaRapida::factory()->create(['atajo' => 'horario', 'texto' => 'De 9 a 6']);
    $cliente = Cliente::factory()->for($this->user)->create(['nombre_contacto' => 'Juan Pérez', 'razon_social' => 'SELLOS JUAN SA DE CV']);
    $conversacion = chatConRespuestasRapidas($this->user, ['cliente_id' => $cliente->id]);

    $html = $this->actingAs($this->user)->get("/whatsapp/{$conversacion->id}")->assertOk()
        ->assertSee('"nombre":"Juan Pérez"', false)
        ->getContent();

    $bloque = bloqueRespuestasRapidas($html);

    expect(array_column($bloque['respuestas'], 'atajo'))->toBe(['horario', 'pago'])
        ->and($bloque['respuestas'][1]['texto'])->toBe('Hola {nombre}, paga a BBVA')
        ->and($bloque['nombre'])->toBe('Juan Pérez')
        ->and($bloque['fecha'])->toBe(now()->locale('es')->translatedFormat('j \d\e F \d\e Y'))
        ->and($bloque['fecha'])->toMatch('/^\d{1,2} de [a-z]+ de \d{4}$/');
});

it('el nombre usa la razón social si no hay contacto, y queda vacío sin cliente', function () {
    $cliente = Cliente::factory()->for($this->user)->create(['nombre_contacto' => null, 'razon_social' => 'SELLOS JUAN SA DE CV']);
    $conversacion = chatConRespuestasRapidas($this->user, ['cliente_id' => $cliente->id]);

    $html = $this->actingAs($this->user)->get("/whatsapp/{$conversacion->id}")->getContent();
    expect(bloqueRespuestasRapidas($html)['nombre'])->toBe('SELLOS JUAN SA DE CV');

    $conversacion->forceFill(['cliente_id' => null])->save();

    $html = $this->actingAs($this->user)->get("/whatsapp/{$conversacion->id}")->getContent();
    expect(bloqueRespuestasRapidas($html)['nombre'])->toBe('');
});

it('con la ventana de 24 horas cerrada no manda las respuestas', function () {
    RespuestaRapida::factory()->create(['atajo' => 'horario']);
    $conversacion = chatConRespuestasRapidas($this->user, ['ultimo_mensaje_cliente_en' => now()->subHours(25)]);

    $this->actingAs($this->user)->get("/whatsapp/{$conversacion->id}")->assertOk()
        ->assertDontSee('id="wa-respuestas-rapidas"', false);
});

it('un texto con etiquetas sale escapado y no rompe la página', function () {
    RespuestaRapida::factory()->create(['atajo' => 'raro', 'texto' => '</script><b>x</b>']);
    $conversacion = chatConRespuestasRapidas($this->user);

    $html = $this->actingAs($this->user)->get("/whatsapp/{$conversacion->id}")->assertOk()
        ->assertDontSee('</script><b>x</b>', false)
        ->getContent();

    expect(bloqueRespuestasRapidas($html)['respuestas'][0]['texto'])->toBe('</script><b>x</b>');
});
