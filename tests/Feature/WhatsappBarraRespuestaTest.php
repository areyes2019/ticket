<?php

use App\Enums\EstadoConversacion;
use App\Models\User;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappNumero;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Barra de respuesta estilo WhatsApp (044, adición 4).
 */
beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();

    Http::fake(fn ($peticion) => match (true) {
        str_ends_with($peticion->url(), '/media') => Http::response(['id' => 'media.'.uniqid()]),
        str_ends_with($peticion->url(), '/messages') => Http::response(['messages' => [['id' => 'wamid.salida.'.uniqid()]]]),
        default => Http::response([]),
    });
});

function chatConBarra(User $user, array $cambios = []): WhatsappConversacion
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

    $conversacion = new WhatsappConversacion;
    $conversacion->forceFill([
        'whatsapp_numero_id' => $numero->id,
        'user_id' => $user->id,
        'wa_id' => '5214491234567',
        'telefono_10' => '4491234567',
        'nombre_perfil' => 'Juan',
        'estado' => EstadoConversacion::Agente,
        'ultimo_mensaje_cliente_en' => now(),
        'ultimo_mensaje_en' => now(),
        ...$cambios,
    ])->save();

    return $conversacion;
}

/**
 * Lo que se le mandó a Meta como mensaje, por tipo.
 *
 * @return list<array<string, mixed>>
 */
function mensajesAMeta(): array
{
    return collect(Http::recorded())
        ->map(fn ($par) => $par[0])
        ->filter(fn ($peticion) => str_ends_with($peticion->url(), '/messages'))
        ->map(fn ($peticion) => $peticion->data())
        ->values()
        ->all();
}

it('pinta la barra con clip, menú de tres opciones y botón de enviar', function () {
    $conversacion = chatConBarra($this->user);

    $this->actingAs($this->user)->get("/whatsapp/{$conversacion->id}")
        ->assertOk()
        ->assertSee('class="wa-barra"', false)
        ->assertSee('aria-haspopup="menu"', false)
        ->assertSee('id="wa-menu-adjuntar"', false)
        ->assertSeeInOrder(['data-adjuntar="documento"', 'data-adjuntar="imagen"', 'data-adjuntar="catalogo"'], false)
        ->assertSeeInOrder(['Documento', 'Imagen', 'Catálogo'])
        ->assertSee('aria-label="Enviar"', false)
        ->assertSee('wa-enviar', false)
        ->assertSee('name="archivo"', false)
        ->assertSee('placeholder="Escribe un mensaje o / para respuestas rápidas"', false);
});

it('catálogo es de adorno: desactivado y con "Próximamente"', function () {
    $conversacion = chatConBarra($this->user);

    $html = $this->actingAs($this->user)->get("/whatsapp/{$conversacion->id}")->getContent();

    preg_match('#<button[^>]*data-adjuntar="catalogo"[^>]*>.*?</button>#s', $html, $m);

    expect($m[0] ?? '')->toContain('aria-disabled="true"')->toContain('Próximamente');
});

it('el aviso de pausa solo sale con el agente activo', function () {
    $conversacion = chatConBarra($this->user);

    $this->actingAs($this->user)->get("/whatsapp/{$conversacion->id}")
        ->assertSee('Contestar pausa el agente en este chat.');

    $conversacion->forceFill(['estado' => EstadoConversacion::Humano])->save();

    $this->actingAs($this->user)->get("/whatsapp/{$conversacion->id}")
        ->assertOk()
        ->assertDontSee('Contestar pausa el agente en este chat.');
});

it('con la ventana cerrada no hay barra y sigue el aviso de plantilla', function () {
    $conversacion = chatConBarra($this->user, ['ultimo_mensaje_cliente_en' => now()->subHours(25)]);

    $this->actingAs($this->user)->get("/whatsapp/{$conversacion->id}")
        ->assertOk()
        ->assertDontSee('class="wa-barra"', false)
        ->assertSee('Solo puedes mandar una plantilla.');
});

it('enviar solo texto sale como texto', function () {
    $conversacion = chatConBarra($this->user);

    $this->actingAs($this->user)->post("/whatsapp/{$conversacion->id}/mensajes", ['texto' => 'Hola'])
        ->assertSessionHasNoErrors()->assertRedirect();

    expect(mensajesAMeta())->toHaveCount(1)
        ->and(mensajesAMeta()[0]['type'])->toBe('text')
        ->and(mensajesAMeta()[0]['text']['body'])->toBe('Hola');
});

it('una imagen con texto sale como imagen con ese pie', function () {
    $conversacion = chatConBarra($this->user);

    $this->actingAs($this->user)->post("/whatsapp/{$conversacion->id}/mensajes", [
        'texto' => 'Así queda tu sello',
        'archivo' => UploadedFile::fake()->image('sello.jpg', 40, 40),
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect(mensajesAMeta()[0]['type'])->toBe('image')
        ->and(mensajesAMeta()[0]['image']['caption'])->toBe('Así queda tu sello')
        ->and(WhatsappMensaje::where('direccion', 'saliente')->sole()->tipo)->toBe('imagen');
});

it('un PDF sin texto sale como documento', function () {
    $conversacion = chatConBarra($this->user);

    $this->actingAs($this->user)->post("/whatsapp/{$conversacion->id}/mensajes", [
        'archivo' => UploadedFile::fake()->create('cotizacion.pdf', 200, 'application/pdf'),
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect(mensajesAMeta()[0]['type'])->toBe('document')
        ->and(WhatsappMensaje::where('direccion', 'saliente')->sole())
        ->tipo->toBe('documento')
        ->archivo_nombre->toBe('cotizacion.pdf');
});

it('rechaza un archivo de más de 10 MB o que no es PDF ni imagen', function (UploadedFile $archivo) {
    $conversacion = chatConBarra($this->user);

    $this->actingAs($this->user)->post("/whatsapp/{$conversacion->id}/mensajes", ['archivo' => $archivo])
        ->assertSessionHasErrors('archivo');

    expect(mensajesAMeta())->toBeEmpty();
})->with([
    'pesado' => fn () => UploadedFile::fake()->create('grande.pdf', 11 * 1024, 'application/pdf'),
    'word' => fn () => UploadedFile::fake()->create('carta.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
]);
