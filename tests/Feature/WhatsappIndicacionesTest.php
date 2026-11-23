<?php

use App\Enums\AgenteWhatsapp;
use App\Models\User;
use App\Models\WhatsappNumero;
use App\Services\Whatsapp\TextoIndicaciones;
use Illuminate\Support\Facades\DB;

/**
 * Indicaciones de los agentes de WhatsApp por secciones (045, adición 7).
 */
function numeroIndicaciones(User $user, array $cambios = []): WhatsappNumero
{
    $numero = new WhatsappNumero([
        'phone_number_id' => '7777777777',
        'waba_id' => '8888888888',
        'token' => 'token-meta',
        'app_secret' => 'secreto-app',
        'verify_token' => 'verificacion-123456',
        ...$cambios,
    ]);
    $numero->user_id = $user->id;
    $numero->save();

    return $numero;
}

function datosWhatsapp(array $cambios = []): array
{
    return [
        'phone_number_id' => '7777777777', 'waba_id' => '8888888888', 'verify_token' => 'verificacion-123456',
        'tope_mensual_usd' => 50, 'limite_hora' => 20, 'limite_dia' => 100,
        ...$cambios,
    ];
}

beforeEach(function () {
    $this->admin = User::factory()->administrador()->create();
    $this->numero = numeroIndicaciones($this->admin);
});

it('guarda las secciones y las preguntas limpias y en orden', function () {
    $this->actingAs($this->admin)->put('/configuracion/whatsapp/agentes', datosWhatsapp([
        'indicaciones_ventas' => [
            'pago' => "  Transferencia\r\ny efectivo  ",
            'entregas' => '',
            'preguntas' => [
                ['pregunta' => '¿Hacen fechadores?', 'respuesta' => 'Sí, en tres tamaños.'],
                ['pregunta' => '¿Envían?', 'respuesta' => ' A todo México. '],
            ],
            'requisitos' => 'no es de ventas',
        ],
    ]))->assertSessionHas('exito');

    expect($this->numero->fresh()->indicaciones_ventas)->toBe([
        'pago' => "Transferencia\ny efectivo",
        'entregas' => null,
        'devoluciones' => null,
        'tono' => null,
        'preguntas' => [
            ['pregunta' => '¿Hacen fechadores?', 'respuesta' => 'Sí, en tres tamaños.'],
            ['pregunta' => '¿Envían?', 'respuesta' => 'A todo México.'],
        ],
        'otras' => null,
    ]);
});

it('rechaza un par con pregunta sin respuesta o respuesta sin pregunta', function () {
    $this->actingAs($this->admin)->put('/configuracion/whatsapp/agentes', datosWhatsapp([
        'indicaciones_ventas' => ['preguntas' => [['pregunta' => '¿Hacen fechadores?', 'respuesta' => '']]],
    ]))->assertSessionHasErrors(['indicaciones_ventas.preguntas.0.respuesta' => 'Falta la respuesta.']);

    $this->actingAs($this->admin)->put('/configuracion/whatsapp/agentes', datosWhatsapp([
        'indicaciones_facturacion' => ['preguntas' => [['pregunta' => '', 'respuesta' => 'Sí.']]],
    ]))->assertSessionHasErrors(['indicaciones_facturacion.preguntas.0.pregunta' => 'Falta la pregunta.']);
});

it('ignora los pares vacíos y numera desde cero', function () {
    $this->actingAs($this->admin)->put('/configuracion/whatsapp/agentes', datosWhatsapp([
        'indicaciones_ventas' => ['preguntas' => [
            3 => ['pregunta' => '', 'respuesta' => '  '],
            5 => ['pregunta' => 'Uno', 'respuesta' => 'A'],
            6 => ['pregunta' => '', 'respuesta' => ''],
            9 => ['pregunta' => 'Dos', 'respuesta' => 'B'],
            12 => ['pregunta' => '', 'respuesta' => ''],
        ]],
    ]))->assertSessionHas('exito');

    expect($this->numero->fresh()->indicaciones_ventas['preguntas'])->toBe([
        ['pregunta' => 'Uno', 'respuesta' => 'A'],
        ['pregunta' => 'Dos', 'respuesta' => 'B'],
    ]);
});

it('valida los límites de secciones, preguntas y respuestas', function (array $indicaciones, string $error) {
    $this->actingAs($this->admin)->put('/configuracion/whatsapp/agentes', datosWhatsapp(['indicaciones_ventas' => $indicaciones]))
        ->assertSessionHasErrors($error);
})->with([
    '31 preguntas' => [['preguntas' => array_fill(0, 31, ['pregunta' => 'P', 'respuesta' => 'R'])], 'indicaciones_ventas.preguntas'],
    'sección larga' => [['pago' => str_repeat('a', 1001)], 'indicaciones_ventas.pago'],
    'pregunta larga' => [['preguntas' => [['pregunta' => str_repeat('a', 201), 'respuesta' => 'R']]], 'indicaciones_ventas.preguntas.0.pregunta'],
    'respuesta larga' => [['preguntas' => [['pregunta' => 'P', 'respuesta' => str_repeat('a', 501)]]], 'indicaciones_ventas.preguntas.0.respuesta'],
]);

it('la migración pasa el texto viejo completo a Otras indicaciones', function () {
    $migracion = require database_path('migrations/2026_11_24_110000_indicaciones_por_secciones_whatsapp.php');
    $largo = str_repeat('Política larga. ', 100);

    $migracion->down();
    DB::table('whatsapp_numeros')->where('id', $this->numero->id)->update([
        'instrucciones_ventas' => "  {$largo}  ",
        'instrucciones_facturacion' => '',
    ]);
    $migracion->up();

    $numero = $this->numero->fresh();
    expect($numero->indicaciones_ventas['otras'])->toBe(trim($largo))
        ->and($numero->indicaciones_ventas['preguntas'])->toBe([])
        ->and($numero->indicaciones_facturacion['otras'])->toBeNull();
});

it('arma el texto del agente con las secciones en orden fijo y sin las vacías', function () {
    $texto = TextoIndicaciones::armar(AgenteWhatsapp::Ventas, [
        'otras' => 'Al final.',
        'preguntas' => [['pregunta' => '¿Envían?', 'respuesta' => "Sí.\nA todo México."], ['pregunta' => 'Incompleta', 'respuesta' => '']],
        'tono' => '   ',
        'pago' => 'Transferencia.',
    ]);

    expect($texto)->toBe(
        "### Formas de pago\n\nTransferencia.\n\n"
        ."### Preguntas frecuentes\n\n".TextoIndicaciones::GUIA_PREGUNTAS."\n\n- P: ¿Envían?\n  R: Sí.\n  A todo México.\n\n"
        .'### Otras indicaciones'."\n\nAl final."
    )->and(TextoIndicaciones::armar(AgenteWhatsapp::Facturacion, ['tono' => '', 'preguntas' => []]))->toBeNull();

    // El agente lo recibe a través del número.
    $this->numero->forceFill(['indicaciones_facturacion' => ['requisitos' => 'RFC y CP.']])->save();
    expect($this->numero->fresh()->instrucciones(AgenteWhatsapp::Facturacion))->toBe("### Requisitos para facturar\n\nRFC y CP.");
});

it('arma exactamente el mismo texto con los mismos datos (caché)', function () {
    $caja = ['pago' => "Uno\r\nDos", 'preguntas' => [['pregunta' => 'P', 'respuesta' => 'R']]];
    $igual = ['pago' => "Uno\nDos", 'preguntas' => [['pregunta' => 'P', 'respuesta' => 'R']]];

    expect(TextoIndicaciones::armar(AgenteWhatsapp::Ventas, $caja))
        ->toBe(TextoIndicaciones::armar(AgenteWhatsapp::Ventas, $caja))
        ->toBe(TextoIndicaciones::armar(AgenteWhatsapp::Ventas, $igual));
});

it('la pestaña muestra los dos agentes, las preguntas más un par vacío, el molde y el contador', function () {
    $this->numero->forceFill(['indicaciones_ventas' => [
        'pago' => 'Efectivo',
        'preguntas' => [['pregunta' => '¿Hacen fechadores?', 'respuesta' => 'Sí.']],
    ]])->save();

    $respuesta = $this->actingAs($this->admin)->get('/configuracion')->assertOk()
        ->assertSee('Agente de ventas encendido')
        ->assertSee('Agente de facturación encendido')
        ->assertSee('Requisitos para facturar')
        ->assertSee('¿Hacen fechadores?')
        ->assertSee('name="indicaciones_ventas[preguntas][1][pregunta]"', false)
        ->assertDontSee('name="indicaciones_ventas[preguntas][2][pregunta]"', false)
        ->assertSee('name="indicaciones_ventas[preguntas][__i__][pregunta]"', false)
        ->assertSee('data-molde-pregunta', false)
        ->assertSee('29 caracteres en total');

    // Con 30 guardadas no hay par vacío.
    $this->numero->forceFill(['indicaciones_ventas' => [
        'preguntas' => array_fill(0, 30, ['pregunta' => 'P', 'respuesta' => 'R']),
    ]])->save();

    $this->actingAs($this->admin->fresh())->get('/configuracion')->assertOk()
        ->assertSee('name="indicaciones_ventas[preguntas][29][pregunta]"', false)
        ->assertDontSee('name="indicaciones_ventas[preguntas][30][pregunta]"', false);
});
