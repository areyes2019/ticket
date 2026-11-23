<?php

use App\Enums\AgenteWhatsapp;
use App\Enums\EstadoConversacion;
use App\Enums\EstadoCotizacion;
use App\Enums\EstadoFactura;
use App\Enums\TipoCuenta;
use App\Jobs\ProcesarConversacion;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\DatoBancario;
use App\Models\Factura;
use App\Models\Pedido;
use App\Models\User;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappNumero;
use App\Models\WhatsappUsoClaude;
use App\Services\Whatsapp\Claude\ClaudeException;
use App\Services\Whatsapp\Claude\ClienteClaude;
use App\Services\Whatsapp\Claude\RespuestaClaude;
use App\Services\Whatsapp\ContextoTurno;
use App\Services\Whatsapp\Herramientas\BuscarArticulos;
use App\Services\Whatsapp\Herramientas\BuscarVenta;
use App\Services\Whatsapp\Herramientas\CrearCotizacion;
use App\Services\Whatsapp\Herramientas\DarDatosBancarios;
use App\Services\Whatsapp\Herramientas\EnviarCotizacion;
use App\Services\Whatsapp\Herramientas\ReenviarFactura;
use App\Services\Whatsapp\Herramientas\TimbrarFactura;
use App\Services\Whatsapp\OrquestadorTurno;
use App\Services\Whatsapp\Telefono;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

/**
 * Claude con respuestas guionadas: cada llamada toma la siguiente.
 */
class ClaudeGuionado implements ClienteClaude
{
    /** @var list<RespuestaClaude|Throwable> */
    public array $guion = [];

    /** @var list<array{system: array, mensajes: array, herramientas: array}> */
    public array $peticiones = [];

    public function crear(array $system, array $mensajes, array $herramientas = []): RespuestaClaude
    {
        $this->peticiones[] = ['system' => $system, 'mensajes' => $mensajes, 'herramientas' => $herramientas];
        $siguiente = array_shift($this->guion) ?? claudeTexto('Listo.');

        if ($siguiente instanceof Throwable) {
            throw $siguiente;
        }

        return $siguiente;
    }

    /**
     * @return list<string>
     */
    public function nombresHerramientas(int $peticion = 0): array
    {
        return array_column($this->peticiones[$peticion]['herramientas'], 'name');
    }
}

function claudeTexto(string $texto): RespuestaClaude
{
    return new RespuestaClaude('end_turn', [['type' => 'text', 'text' => $texto]], [['type' => 'text', 'text' => $texto]], ['entrada' => 1000, 'salida' => 100, 'cache_lectura' => 0, 'cache_escritura' => 0], 'claude-opus-5-5');
}

/**
 * @param  array<string, mixed>  $entrada
 */
function claudeHerramienta(string $nombre, array $entrada = []): RespuestaClaude
{
    $bloque = ['type' => 'tool_use', 'id' => 'toolu_'.uniqid(), 'name' => $nombre, 'input' => $entrada];

    return new RespuestaClaude('tool_use', [$bloque], [$bloque], ['entrada' => 1000, 'salida' => 50, 'cache_lectura' => 0, 'cache_escritura' => 0], 'claude-opus-5-5');
}

function numeroWhatsapp(User $user, array $cambios = []): WhatsappNumero
{
    $numero = new WhatsappNumero([
        'phone_number_id' => '1111111111',
        'waba_id' => '2222222222',
        'token' => 'token-meta',
        'app_secret' => 'secreto-app',
        'verify_token' => 'verificacion-123456',
        'ventas_activo' => true,
        'facturacion_activo' => true,
        ...$cambios,
    ]);
    $numero->user_id = $user->id;
    $numero->save();

    return $numero;
}

/**
 * @param  list<array<string, mixed>>  $mensajes
 * @return array<string, mixed>
 */
function avisoMeta(array $mensajes, array $estados = [], string $phoneNumberId = '1111111111', string $nombre = 'Juan'): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [[
        'id' => '2222222222',
        'changes' => [[
            'field' => 'messages',
            'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '524490000000', 'phone_number_id' => $phoneNumberId],
                'contacts' => [['profile' => ['name' => $nombre], 'wa_id' => '5214491234567']],
                'messages' => $mensajes,
                'statuses' => $estados,
            ],
        ]],
    ]]];
}

/**
 * @return array<string, mixed>
 */
function textoCliente(string $texto, ?string $wamid = null): array
{
    return ['from' => '5214491234567', 'id' => $wamid ?? 'wamid.'.uniqid(), 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $texto]];
}

function enviarAviso(array $aviso, string $secreto = 'secreto-app')
{
    $cuerpo = json_encode($aviso);

    return test()->call('POST', '/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $cuerpo, $secreto),
    ], $cuerpo);
}

/**
 * Un mensaje del cliente ya guardado en una conversación abierta, sin pasar
 * por el webhook.
 */
function mensajeCliente(WhatsappConversacion $conversacion, string $texto, array $cambios = []): WhatsappMensaje
{
    $conversacion->forceFill(['ultimo_mensaje_cliente_en' => now(), 'ultimo_mensaje_en' => now()])->save();

    return $conversacion->mensajes()->create([
        'wamid' => 'wamid.'.uniqid(),
        'direccion' => 'entrante',
        'autor' => 'cliente',
        'tipo' => 'texto',
        'texto' => $texto,
        'enviado_en' => now(),
        ...$cambios,
    ]);
}

function correrTurno(WhatsappConversacion $conversacion): bool
{
    return app(OrquestadorTurno::class)->correr($conversacion->fresh());
}

function metaFalso(): void
{
    Http::fake(function ($peticion) {
        $url = $peticion->url();

        return match (true) {
            str_contains($url, 'facturapi.io') && str_ends_with($url, '/xml') => Http::response('<cfdi:Comprobante/>'),
            str_contains($url, 'facturapi.io') => Http::response(respuestaTimbrado()),
            str_ends_with($url, '/media') => Http::response(['id' => 'media.'.uniqid()]),
            str_ends_with($url, '/messages') => Http::response(['messages' => [['id' => 'wamid.salida.'.uniqid()]]]),
            default => Http::response([]),
        };
    });
}

/**
 * Lo que se le mandó al cliente como texto.
 *
 * @return list<string>
 */
function textosEnviados(): array
{
    return collect(Http::recorded())
        ->map(fn ($par) => $par[0])
        ->filter(fn ($peticion) => str_ends_with($peticion->url(), '/messages') && ($peticion['type'] ?? null) === 'text')
        ->map(fn ($peticion) => $peticion['text']['body'])
        ->values()
        ->all();
}

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    config([
        'services.facturapi.llave' => 'sk_test_prueba',
        'services.facturapi.emisor' => ['rfc' => 'EKU9003173C9', 'razon_social' => 'ESCUELA KEMPER URGATE', 'regimen_fiscal' => '601', 'codigo_postal' => '26015'],
    ]);

    $this->claude = new ClaudeGuionado;
    app()->instance(ClienteClaude::class, $this->claude);

    $this->user = User::factory()->create();
    $this->numero = numeroWhatsapp($this->user);
});

function conversacion(array $cambios = []): WhatsappConversacion
{
    $conversacion = new WhatsappConversacion;
    $conversacion->forceFill([
        'whatsapp_numero_id' => test()->numero->id,
        'user_id' => test()->user->id,
        'wa_id' => '5214491234567',
        'telefono_10' => '4491234567',
        'nombre_perfil' => 'Juan',
        'ultimo_mensaje_cliente_en' => now(),
        ...$cambios,
    ])->save();

    return $conversacion;
}

// --- Teléfonos (regla 76) ---

it('compara teléfonos por sus últimos 10 dígitos', function (string $telefono) {
    expect(Telefono::diezDigitos($telefono))->toBe('4491234567');
})->with(['5214491234567', '524491234567', '+52 449 123 4567', '4491234567', '(449) 123-45-67']);

it('el cliente guarda sus últimos 10 dígitos al guardarse', function () {
    $cliente = Cliente::factory()->for($this->user)->create(['telefono' => '+52 449 123 4567']);

    expect($cliente->telefono_10)->toBe('4491234567');
});

// --- Webhook (reglas 12 a 17, adiciones 1 y 3) ---

it('verifica el webhook con el token correcto y rechaza los demás', function () {
    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verificacion-123456&hub.challenge=reto42')
        ->assertOk()->assertSee('reto42');

    $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=otro&hub.challenge=reto42')->assertForbidden();
});

it('guarda el mensaje y encola un turno con una firma válida', function () {
    Bus::fake();

    enviarAviso(avisoMeta([textoCliente('Hola', 'wamid.A')]))->assertOk();

    $conversacion = WhatsappConversacion::sole();

    expect($conversacion)
        ->wa_id->toBe('5214491234567')
        ->telefono_10->toBe('4491234567')
        ->nombre_perfil->toBe('Juan')
        ->user_id->toBe($this->user->id)
        ->estado->toBe(EstadoConversacion::Agente)
        ->agente_actual->toBe(AgenteWhatsapp::Ventas)
        ->ultimo_mensaje_cliente_en->not->toBeNull()
        ->and(WhatsappMensaje::sole())->texto->toBe('Hola')->respondido->toBeFalse();

    Bus::assertDispatched(ProcesarConversacion::class, fn ($job) => $job->conversacionId === $conversacion->id);
});

it('rechaza un aviso sin firma o con firma mala y no guarda nada', function () {
    Bus::fake();
    $cuerpo = json_encode(avisoMeta([textoCliente('Hola')]));

    $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json'], $cuerpo)->assertForbidden();
    enviarAviso(avisoMeta([textoCliente('Hola')]), 'clave-equivocada')->assertForbidden();

    expect(WhatsappMensaje::count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('un aviso repetido no se guarda ni se procesa dos veces', function () {
    Bus::fake();

    enviarAviso(avisoMeta([textoCliente('Hola', 'wamid.REPETIDO')]))->assertOk();
    enviarAviso(avisoMeta([textoCliente('Hola', 'wamid.REPETIDO')]))->assertOk();

    expect(WhatsappMensaje::count())->toBe(1);
    Bus::assertDispatchedTimes(ProcesarConversacion::class, 1);
});

it('ignora los avisos de otro número', function () {
    Bus::fake();

    enviarAviso(avisoMeta([textoCliente('Hola')], phoneNumberId: '999999'))->assertOk();

    expect(WhatsappConversacion::count())->toBe(0);
});

it('actualiza el estado de un mensaje enviado sin retroceder', function () {
    $conversacion = conversacion();
    $enviado = $conversacion->mensajes()->create(['wamid' => 'wamid.OUT', 'direccion' => 'saliente', 'autor' => 'ventas', 'tipo' => 'texto', 'texto' => 'Hola', 'estado_envio' => 'enviado', 'respondido' => true]);

    enviarAviso(avisoMeta([], [['id' => 'wamid.OUT', 'status' => 'read']]))->assertOk();
    enviarAviso(avisoMeta([], [['id' => 'wamid.OUT', 'status' => 'delivered']]))->assertOk();
    expect($enviado->fresh()->estado_envio)->toBe('leido');

    enviarAviso(avisoMeta([], [['id' => 'wamid.OUT', 'status' => 'failed', 'errors' => [['title' => 'x', 'error_data' => ['details' => 'Número inválido']]]]]))->assertOk();
    expect($enviado->fresh())->estado_envio->toBe('fallido')->error_envio->toBe('Número inválido');
});

it('liga la conversación al cliente si el teléfono es de uno solo', function () {
    Bus::fake();
    $cliente = Cliente::factory()->for($this->user)->create(['telefono' => '4491234567']);

    enviarAviso(avisoMeta([textoCliente('Hola')]));

    expect(WhatsappConversacion::sole()->cliente_id)->toBe($cliente->id);
});

it('reabre una conversación cerrada cuando el cliente vuelve a escribir', function () {
    Bus::fake();
    $conversacion = conversacion(['estado' => EstadoConversacion::Cerrada, 'agente_actual' => AgenteWhatsapp::Facturacion]);

    enviarAviso(avisoMeta([textoCliente('Hola otra vez')]));

    expect($conversacion->fresh())->estado->toBe(EstadoConversacion::Agente)->agente_actual->toBe(AgenteWhatsapp::Ventas);
});

// --- Turnos (reglas 20 a 33) ---

it('junta mensajes seguidos en un solo turno y una sola respuesta', function () {
    metaFalso();
    $conversacion = conversacion();
    mensajeCliente($conversacion, 'hola');
    mensajeCliente($conversacion, 'quiero un sello');
    mensajeCliente($conversacion, 'de 4x2');
    $this->claude->guion = [claudeTexto('¡Hola! Con gusto te ayudo.')];

    expect(correrTurno($conversacion))->toBeFalse();

    expect($this->claude->peticiones)->toHaveCount(1)
        ->and(textosEnviados())->toBe(['¡Hola! Con gusto te ayudo.'])
        ->and(WhatsappMensaje::where('direccion', 'entrante')->where('respondido', false)->count())->toBe(0)
        ->and(WhatsappUsoClaude::sole())->tokens_entrada->toBe(1000);

    $ultimo = end($this->claude->peticiones[0]['mensajes']);
    $textos = collect($ultimo['content'])->pluck('text')->implode("\n");
    expect($ultimo['role'])->toBe('user')->and($textos)->toContain('hola')->toContain('quiero un sello')->toContain('de 4x2');
});

it('"asesor" pasa a humano sin llamar a Claude, con aviso una sola vez', function () {
    metaFalso();
    $conversacion = conversacion();
    mensajeCliente($conversacion, 'Asesor, por favor');

    correrTurno($conversacion);
    mensajeCliente($conversacion, 'hola?');
    correrTurno($conversacion);

    expect($this->claude->peticiones)->toBeEmpty()
        ->and($conversacion->fresh())->estado->toBe(EstadoConversacion::Humano)->motivo_humano->toBe('El cliente pidió un asesor')
        ->and(textosEnviados())->toHaveCount(1);
});

it('reconoce las palabras para pedir humano', function (string $texto, bool $esperado) {
    expect(OrquestadorTurno::esPalabraHumano($texto))->toBe($esperado);
})->with([
    ['asesor', true],
    ['ASESOR!!', true],
    ['Humano por favor', true],
    ['agente humano', true],
    ['quiero hablar con alguien', false],
    ['mi asesor fiscal dice que...', false],
]);

it('una nota de voz recibe el aviso una vez al día y no llama a Claude', function () {
    metaFalso();
    $conversacion = conversacion();
    mensajeCliente($conversacion, '', ['tipo' => 'audio', 'archivo_ruta' => 'whatsapp/x.ogg', 'archivo_mime' => 'audio/ogg']);
    correrTurno($conversacion);
    mensajeCliente($conversacion, '', ['tipo' => 'audio', 'archivo_ruta' => 'whatsapp/y.ogg', 'archivo_mime' => 'audio/ogg']);
    correrTurno($conversacion);

    expect($this->claude->peticiones)->toBeEmpty()
        ->and(textosEnviados())->toBe([OrquestadorTurno::AVISO_AUDIO]);
});

it('con el agente apagado la conversación pasa a humano', function () {
    metaFalso();
    $this->numero->forceFill(['ventas_activo' => false])->save();
    $conversacion = conversacion();
    mensajeCliente($conversacion, 'hola');

    correrTurno($conversacion);

    expect($this->claude->peticiones)->toBeEmpty()
        ->and($conversacion->fresh()->estado)->toBe(EstadoConversacion::Humano);
});

it('en manos de humano el agente no contesta', function () {
    metaFalso();
    $conversacion = conversacion(['estado' => EstadoConversacion::Humano]);
    mensajeCliente($conversacion, 'hola');

    correrTurno($conversacion);

    expect($this->claude->peticiones)->toBeEmpty()->and(textosEnviados())->toBeEmpty();
});

it('con el tope de gasto alcanzado pasa a humano sin llamar a Claude', function () {
    metaFalso();
    $this->numero->forceFill(['tope_mensual_usd' => 1])->save();
    WhatsappUsoClaude::create(['user_id' => $this->user->id, 'modelo' => 'claude-opus-5-5', 'costo_usd' => 1.5]);
    $conversacion = conversacion();
    mensajeCliente($conversacion, 'hola');

    correrTurno($conversacion);

    expect($this->claude->peticiones)->toBeEmpty()
        ->and($conversacion->fresh())->estado->toBe(EstadoConversacion::Humano)->motivo_humano->toBe('Tope de gasto mensual');
});

it('al rebasar el límite por hora avisa una vez y pasa a humano; sin límite sigue', function () {
    metaFalso();
    $this->numero->forceFill(['limite_hora' => 2])->save();
    $conversacion = conversacion();
    RateLimiter::clear('wa-hora:'.$conversacion->id);

    foreach (range(1, 3) as $i) {
        mensajeCliente($conversacion, 'mensaje '.$i);
        correrTurno($conversacion);
    }

    expect($this->claude->peticiones)->toHaveCount(2)
        ->and($conversacion->fresh())->estado->toBe(EstadoConversacion::Humano)->motivo_humano->toBe('Límite de mensajes')
        ->and(textosEnviados())->toContain(OrquestadorTurno::AVISO_LIMITE);

    $conversacion->fresh()->forceFill(['sin_limite' => true, 'estado' => EstadoConversacion::Agente])->save();
    mensajeCliente($conversacion, 'sigo aquí');
    correrTurno($conversacion);

    expect($this->claude->peticiones)->toHaveCount(3);
});

it('una falla pasajera de Claude sin nada hecho se relanza para reintentar', function () {
    metaFalso();
    $conversacion = conversacion();
    mensajeCliente($conversacion, 'hola');
    $this->claude->guion = [new ClaudeException('saturado', pasajera: true)];

    expect(fn () => correrTurno($conversacion))->toThrow(ClaudeException::class);
    expect($conversacion->mensajes()->where('direccion', 'entrante')->where('respondido', false)->count())->toBe(1);
});

it('a la tercera falla del trabajo pasa a humano con aviso al cliente', function () {
    metaFalso();
    $conversacion = conversacion();
    mensajeCliente($conversacion, 'hola');

    (new ProcesarConversacion($conversacion->id))->failed(new RuntimeException('Claude caído'));

    expect($conversacion->fresh())->estado->toBe(EstadoConversacion::Humano)->motivo_humano->toStartWith('Falla técnica')
        ->and(textosEnviados())->toBe([OrquestadorTurno::AVISO_FALLA]);
});

it('un rechazo de Claude pasa a humano', function () {
    metaFalso();
    $conversacion = conversacion();
    mensajeCliente($conversacion, 'hola');
    $this->claude->guion = [new RespuestaClaude('refusal', [], [], ['entrada' => 10, 'salida' => 0, 'cache_lectura' => 0, 'cache_escritura' => 0], 'claude-opus-5-5')];

    correrTurno($conversacion);

    expect($conversacion->fresh()->estado)->toBe(EstadoConversacion::Humano);
});

it('si la ventana se cerró antes de responder, no manda el mensaje y pasa a humano', function () {
    metaFalso();
    $conversacion = conversacion();
    mensajeCliente($conversacion, 'hola');
    $conversacion->forceFill(['ultimo_mensaje_cliente_en' => now()->subHours(25)])->save();

    correrTurno($conversacion);

    expect(textosEnviados())->toBeEmpty()
        ->and($conversacion->fresh()->motivo_humano)->toBe('Se cerró la ventana de 24 h antes de responder');
});

it('transfiere a facturación en el mismo turno y contesta el agente de facturación', function () {
    metaFalso();
    $conversacion = conversacion();
    mensajeCliente($conversacion, 'quiero mi factura');
    $this->claude->guion = [claudeHerramienta('transferir_a_facturacion'), claudeTexto('Claro, ¿me das el folio de tu ticket?')];

    correrTurno($conversacion);

    expect($conversacion->fresh()->agente_actual)->toBe(AgenteWhatsapp::Facturacion)
        ->and($this->claude->nombresHerramientas(1))->toContain('timbrar_factura')->not->toContain('crear_cotizacion')
        ->and(WhatsappMensaje::where('direccion', 'saliente')->sole()->autor)->toBe('facturacion')
        ->and(textosEnviados())->toBe(['Claro, ¿me das el folio de tu ticket?']);
});

it('cada agente solo recibe sus herramientas', function () {
    metaFalso();
    $conversacion = conversacion();
    mensajeCliente($conversacion, 'hola');

    correrTurno($conversacion);

    expect($this->claude->nombresHerramientas())->toBe(['buscar_articulos', 'crear_cotizacion', 'enviar_cotizacion', 'dar_datos_bancarios', 'pasar_a_humano', 'transferir_a_facturacion']);
});

it('el agente pasa a humano con su motivo y el uso de la herramienta queda en la bandeja', function () {
    metaFalso();
    $conversacion = conversacion();
    mensajeCliente($conversacion, 'ya les deposité');
    $this->claude->guion = [claudeHerramienta('pasar_a_humano', ['motivo' => 'Comprobante de pago'])];

    correrTurno($conversacion);

    expect($conversacion->fresh())->estado->toBe(EstadoConversacion::Humano)->motivo_humano->toBe('Comprobante de pago')
        ->and(WhatsappMensaje::where('tipo', 'herramienta')->sole()->texto)->toBe('pasó a humano: Comprobante de pago');
});

it('parte una respuesta larga en mensajes de menos de 1000 caracteres', function () {
    $parrafo = str_repeat('a', 600);
    $partes = OrquestadorTurno::partir(implode("\n\n", array_fill(0, 4, $parrafo)), 5);

    expect($partes)->toHaveCount(4)
        ->and(collect($partes)->every(fn ($p) => mb_strlen($p) < 1000))->toBeTrue()
        ->and(OrquestadorTurno::partir(implode("\n\n", array_fill(0, 8, $parrafo)), 3))->toHaveCount(3);
});

// --- Herramientas de ventas (reglas 34 a 43) ---

it('buscar_articulos solo regresa artículos de la cuenta con su precio público', function () {
    $articulo = articuloFacturable($this->user, ['nombre' => 'Sello autoentintable 4x2', 'modelo' => 'P-4020']);
    articuloFacturable(User::factory()->create(), ['nombre' => 'Sello autoentintable ajeno']);
    $turno = new ContextoTurno(conversacion());

    $resultado = app(BuscarArticulos::class)->ejecutar($turno, ['texto' => 'sello autoentintable']);

    expect($resultado->datos['articulos'])->toHaveCount(1)
        ->and($resultado->datos['articulos'][0])->toBe([
            'id' => $articulo->id,
            'nombre' => 'Sello autoentintable 4x2',
            'modelo' => 'P-4020',
            'precio_con_iva' => $articulo->precio_unitario_con_iva,
        ])
        ->and($resultado->json())->not->toContain('costo')->not->toContain('distribuidor');
});

it('crear_cotizacion rechaza artículos de otra cuenta y nunca toma un precio del agente', function () {
    $ajeno = articuloFacturable(User::factory()->create());
    $turno = new ContextoTurno(conversacion());

    $resultado = app(CrearCotizacion::class)->ejecutar($turno, ['lineas' => [['articulo_id' => $ajeno->id, 'cantidad' => 1]], 'notas' => null, 'contacto_nombre' => null]);

    expect($resultado->error)->toBeTrue()->and(Cotizacion::count())->toBe(0);
});

it('crear_cotizacion para un prospecto va a PÚBLICO EN GENERAL con atención a la persona', function () {
    $articulo = articuloFacturable($this->user);
    $conversacion = conversacion();

    foreach ([1, 2] as $vez) {
        $resultado = app(CrearCotizacion::class)->ejecutar(new ContextoTurno($conversacion->fresh()), [
            'lineas' => [['articulo_id' => $articulo->id, 'cantidad' => 2]],
            'notas' => 'Texto: Juan Pérez · tinta azul',
            'contacto_nombre' => 'Juan Pérez',
        ]);
        expect($resultado->error)->toBeFalse();
    }

    $cotizacion = Cotizacion::with('lineas')->latest('id')->first();

    expect(Cliente::where('rfc', 'XAXX010101000')->count())->toBe(1)
        ->and($cotizacion)
        ->cliente->razon_social->toBe('PÚBLICO EN GENERAL')
        ->contacto_nombre->toBe('Juan Pérez')
        ->contacto_telefono->toBe('449 123 4567')
        ->notas->toBe('Texto: Juan Pérez · tinta azul')
        ->whatsapp_conversacion_id->toBe($conversacion->id)
        ->and($cotizacion->lineas[0]->precio_unitario)->toBe($articulo->precio_unitario_sin_iva);
});

it('crear_cotizacion aplica el descuento permanente del cliente y da precio público al distribuidor', function () {
    $articulo = articuloFacturable($this->user);
    $cliente = Cliente::factory()->for($this->user)->create(['telefono' => '4491234567', 'descuento_permanente' => '10.00', 'es_distribuidor' => true]);
    $conversacion = conversacion(['cliente_id' => $cliente->id]);

    app(CrearCotizacion::class)->ejecutar(new ContextoTurno($conversacion), ['lineas' => [['articulo_id' => $articulo->id, 'cantidad' => 1]], 'notas' => null, 'contacto_nombre' => null]);

    $linea = Cotizacion::sole()->lineas()->sole();

    expect($linea->precio_unitario)->toBe($articulo->precio_unitario_sin_iva)
        ->and($linea->descuento_valor)->toBe('10.00')
        ->and(Cotizacion::sole()->descuento_cliente_porcentaje)->toBe('10.00');
});

it('enviar_cotizacion manda el PDF de una cotización de esta conversación y la deja enviada', function () {
    metaFalso();
    $articulo = articuloFacturable($this->user);
    $conversacion = conversacion();
    $creada = app(CrearCotizacion::class)->ejecutar(new ContextoTurno($conversacion), ['lineas' => [['articulo_id' => $articulo->id, 'cantidad' => 1]], 'notas' => null, 'contacto_nombre' => 'Juan']);

    $resultado = app(EnviarCotizacion::class)->ejecutar(new ContextoTurno($conversacion->fresh()), ['cotizacion_id' => $creada->datos['cotizacion_id']]);

    expect($resultado->error)->toBeFalse()
        ->and(Cotizacion::sole()->estado)->toBe(EstadoCotizacion::Enviada)
        ->and(WhatsappMensaje::where('tipo', 'documento')->sole()->datos['cotizacion_id'])->toBe($creada->datos['cotizacion_id']);
});

it('enviar_cotizacion no manda cotizaciones de otra conversación', function () {
    $otra = Cotizacion::factory()->for($this->user)->create();
    $turno = new ContextoTurno(conversacion());

    expect(app(EnviarCotizacion::class)->ejecutar($turno, ['cotizacion_id' => $otra->id])->error)->toBeTrue();
});

it('dar_datos_bancarios regresa los datos visibles en cotizaciones', function () {
    $dato = DatoBancario::factory()->create(['nombre_banco' => 'BBVA']);
    $turno = new ContextoTurno(conversacion());

    $resultado = app(DarDatosBancarios::class)->ejecutar($turno, []);

    expect($resultado->datos['cuentas'][0])->toMatchArray(['banco' => 'BBVA', 'clabe' => $dato->clabe]);
});

// --- Herramientas de facturación (reglas 44 a 51) ---

function ventaPagada(User $user, string $telefono = '449 123 4567'): Pedido
{
    $articulo = articuloFacturable($user);
    marcarExistencia($articulo, 10);
    $cuenta = Cuenta::factory()->for($user)->create(['tipo' => TipoCuenta::Efectivo]);

    test()->actingAs($user)->post('/pedidos', datosPedido([lineaPedido($articulo, 1, '100.00')], ['cliente_telefono' => $telefono]));
    $pedido = Pedido::latest('id')->first();
    test()->actingAs($user)->post("/pedidos/{$pedido->id}/pagos", ['cuenta_id' => $cuenta->id, 'fecha_pago' => today('America/Mexico_City')->toDateString(), 'monto' => $pedido->total]);
    auth()->logout();

    return $pedido->fresh();
}

/**
 * @return array<string, mixed>
 */
function datosTimbrado(Pedido $pedido, array $cambios = []): array
{
    return [
        'venta_id' => $pedido->id,
        'rfc' => 'XAXX010101000',
        'razon_social' => 'PUBLICO EN GENERAL',
        'regimen_fiscal' => '616',
        'codigo_postal_fiscal' => '20000',
        'uso_cfdi' => 'S01',
        'correo' => null,
        'cliente_confirmo' => true,
        ...$cambios,
    ];
}

it('buscar_venta encuentra la venta por folio solo con el mismo teléfono', function () {
    $pedido = ventaPagada($this->user);
    $turno = new ContextoTurno(conversacion());

    $resultado = app(BuscarVenta::class)->ejecutar($turno, ['folio' => $pedido->numero_ticket, 'enlace' => null, 'archivo_id' => null]);

    expect($resultado->error)->toBeFalse()
        ->and($resultado->datos)->toMatchArray(['venta_id' => $pedido->id, 'se_puede_facturar' => true])
        ->and($turno->ventasValidadas)->toBe([$pedido->id]);
});

it('buscar_venta no encuentra por folio una venta de otro teléfono, pero sí con su enlace', function () {
    $pedido = ventaPagada($this->user, '449 999 9999');
    $turno = new ContextoTurno(conversacion());

    $porFolio = app(BuscarVenta::class)->ejecutar($turno, ['folio' => $pedido->numero_ticket, 'enlace' => null, 'archivo_id' => null]);
    expect($porFolio->error)->toBeTrue()->and($turno->ventasValidadas)->toBeEmpty();

    $porEnlace = app(BuscarVenta::class)->ejecutar($turno, ['folio' => null, 'enlace' => 'Mi enlace: '.$pedido->urlAutofactura(), 'archivo_id' => null]);
    expect($porEnlace->error)->toBeFalse()->and($turno->ventasValidadas)->toBe([$pedido->id]);
});

it('timbrar_factura exige la confirmación del cliente y una venta validada', function () {
    $pedido = ventaPagada($this->user);
    $turno = new ContextoTurno(conversacion());

    expect(app(TimbrarFactura::class)->ejecutar($turno, datosTimbrado($pedido))->error)->toBeTrue();

    $turno->ventasValidadas = [$pedido->id];
    expect(app(TimbrarFactura::class)->ejecutar($turno, datosTimbrado($pedido, ['cliente_confirmo' => false]))->error)->toBeTrue()
        ->and(Factura::count())->toBe(0);
});

it('timbrar_factura timbra, manda PDF y XML por el chat y no timbra dos veces', function () {
    metaFalso();
    $pedido = ventaPagada($this->user);
    $conversacion = conversacion();
    $turno = new ContextoTurno($conversacion);
    $turno->ventasValidadas = [$pedido->id];

    $resultado = app(TimbrarFactura::class)->ejecutar($turno, datosTimbrado($pedido));

    expect($resultado->error)->toBeFalse()
        ->and(Factura::sole())->estado->toBe(EstadoFactura::Timbrada)->whatsapp_conversacion_id->toBe($conversacion->id)
        ->and(WhatsappMensaje::where('tipo', 'documento')->pluck('archivo_mime')->all())->toBe(['application/pdf', 'text/xml']);

    $otra = app(TimbrarFactura::class)->ejecutar($turno, datosTimbrado($pedido));

    expect($otra->error)->toBeTrue()
        ->and($otra->datos['error'])->toBe('Esta venta ya se facturó.')
        ->and(Factura::count())->toBe(1);

    Mail::assertNothingSent();
});

it('reenviar_factura no manda facturas ajenas y responde igual exista o no', function () {
    metaFalso();
    $ajena = ventaPagada($this->user, '449 999 9999');
    $turnoAjeno = new ContextoTurno(conversacion(['wa_id' => '5214499999999', 'telefono_10' => '4499999999']));
    $turnoAjeno->ventasValidadas = [$ajena->id];
    app(TimbrarFactura::class)->ejecutar($turnoAjeno, datosTimbrado($ajena));
    $factura = Factura::sole();

    $turno = new ContextoTurno(conversacion());

    expect(app(ReenviarFactura::class)->ejecutar($turno, ['folio' => $factura->folioVisible()])->datos['error'])->toBe(ReenviarFactura::NO_ENCONTRADA)
        ->and(app(ReenviarFactura::class)->ejecutar($turno, ['folio' => 'NOEXISTE'])->datos['error'])->toBe(ReenviarFactura::NO_ENCONTRADA)
        ->and(app(ReenviarFactura::class)->ejecutar($turnoAjeno, ['folio' => null])->error)->toBeFalse();
});

// --- Bandeja (reglas 56 a 66) ---

it('la bandeja y la conversación solo las ve la cuenta dueña', function () {
    $conversacion = conversacion();
    mensajeCliente($conversacion, 'Hola, ¿tienen sellos?');

    $this->actingAs($this->user)->get('/whatsapp')->assertOk()->assertSee('Juan');
    $this->actingAs($this->user)->get("/whatsapp/{$conversacion->id}")->assertOk()->assertSee('Hola, ¿tienen sellos?');

    $this->actingAs(User::factory()->create())->get("/whatsapp/{$conversacion->id}")->assertForbidden();
});

it('contestar desde la bandeja pasa la conversación a humano sin aviso', function () {
    metaFalso();
    $conversacion = conversacion();
    mensajeCliente($conversacion, 'hola');

    $this->actingAs($this->user)->post("/whatsapp/{$conversacion->id}/mensajes", ['texto' => 'Hola, soy Ana'])->assertRedirect();

    expect($conversacion->fresh())->estado->toBe(EstadoConversacion::Humano)->pendientes_humano->toBe(0)
        ->and(textosEnviados())->toBe(['Hola, soy Ana'])
        ->and(WhatsappMensaje::where('direccion', 'saliente')->sole()->user_id)->toBe($this->user->id);
});

it('con la ventana cerrada rechaza el mensaje libre', function () {
    metaFalso();
    $conversacion = conversacion(['ultimo_mensaje_cliente_en' => now()->subHours(30)]);

    $this->actingAs($this->user)->post("/whatsapp/{$conversacion->id}/mensajes", ['texto' => 'Hola'])->assertSessionHas('error');

    expect(textosEnviados())->toBeEmpty();
});

it('una plantilla sí sale con la ventana cerrada', function () {
    metaFalso();
    $conversacion = conversacion(['ultimo_mensaje_cliente_en' => now()->subHours(30)]);
    $plantilla = $this->numero->plantillas()->create(['nombre' => 'pedido_listo', 'idioma' => 'es_MX', 'cuerpo' => 'Hola {{1}}, tu pedido está listo.', 'variables' => 1, 'estado' => 'APPROVED']);

    $this->actingAs($this->user)->post("/whatsapp/{$conversacion->id}/plantilla", ['plantilla_id' => $plantilla->id, 'variables' => ['Juan']])->assertSessionHas('exito');

    Http::assertSent(fn ($p) => ($p['type'] ?? null) === 'template' && $p['template']['name'] === 'pedido_listo' && $p['template']['components'][0]['parameters'][0]['text'] === 'Juan');
    expect(WhatsappMensaje::where('tipo', 'plantilla')->sole()->texto)->toBe('Hola Juan, tu pedido está listo.');
});

it('reactivar deja lo pendiente atendido y el agente espera el siguiente mensaje', function () {
    $conversacion = conversacion(['estado' => EstadoConversacion::Humano, 'pendientes_humano' => 2]);
    mensajeCliente($conversacion, 'hola');

    $this->actingAs($this->user)->patch("/whatsapp/{$conversacion->id}/estado", ['accion' => 'reactivar'])->assertRedirect();

    expect($conversacion->fresh())->estado->toBe(EstadoConversacion::Agente)->pendientes_humano->toBe(0)
        ->and($conversacion->mensajes()->where('direccion', 'entrante')->where('respondido', false)->count())->toBe(0);
});

it('la barra cuenta los chats que requieren humano', function () {
    conversacion(['estado' => EstadoConversacion::Humano, 'pendientes_humano' => 3]);

    $this->actingAs($this->user)->get('/dashboard')->assertSee('WhatsApp: 1 chats requieren humano', false);
});

// --- Configuración (reglas 4 a 7) ---

it('solo el administrador configura el número y un mismo número no se liga a dos cuentas', function () {
    $admin = User::factory()->administrador()->create();
    $datos = [
        'phone_number_id' => '1111111111', 'waba_id' => '3333333333', 'token' => 'x', 'app_secret' => 'y', 'verify_token' => 'verificacion-abcdef',
        'tope_mensual_usd' => 50, 'limite_hora' => 20, 'limite_dia' => 100,
    ];

    $this->actingAs($this->user)->put('/configuracion/whatsapp', $datos)->assertForbidden();
    $this->actingAs($admin)->put('/configuracion/whatsapp', $datos)->assertSessionHasErrors(['phone_number_id' => 'Este número ya está conectado a otra cuenta.']);

    $this->actingAs($admin)->put('/configuracion/whatsapp', [...$datos, 'phone_number_id' => '4444444444', 'horario' => ['lun' => ['abierto' => 1, 'abre' => '10:00', 'cierra' => '14:00']]])->assertSessionHas('exito');

    $numero = $admin->fresh()->whatsappNumero;
    expect($numero)->token->toBe('x')->ventas_activo->toBeFalse()
        ->and($numero->horarioSemanal()['lun'])->toBe(['10:00', '14:00'])
        ->and($numero->horarioSemanal()['mar'])->toBeNull();

    // Dejar el token vacío lo conserva.
    $this->actingAs($admin->fresh())->put('/configuracion/whatsapp', [...$datos, 'phone_number_id' => '4444444444', 'token' => '', 'app_secret' => ''])->assertSessionHas('exito');
    expect($numero->fresh()->token)->toBe('x');
});

it('el aviso fuera de horario lleva el horario de atención', function () {
    $this->numero->forceFill(['horario' => ['lun' => ['09:00', '18:00'], 'mar' => null, 'mie' => null, 'jue' => null, 'vie' => null, 'sab' => null, 'dom' => null]])->save();
    $lunesNoche = now(config('app.zona_negocio'))->next('Monday')->setTime(22, 0);

    expect($this->numero->fresh()->avisoHumano($lunesNoche))->toContain('lunes de 09:00 a 18:00')
        ->and($this->numero->fresh()->avisoHumano($lunesNoche->copy()->setTime(10, 0)))->toBe(WhatsappNumero::AVISO_HUMANO);
});

it('descarga la imagen del cliente en el turno y se la pasa a Claude como imagen', function () {
    Http::fake([
        'graph.facebook.com/*/media.IMG' => Http::response(['url' => 'https://lookaside.fbsbx.com/foto', 'mime_type' => 'image/jpeg', 'file_size' => 12]),
        'lookaside.fbsbx.com/*' => Http::response('bytes-de-foto'),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.salida']]]),
    ]);
    $conversacion = conversacion();
    $imagen = mensajeCliente($conversacion, 'este es mi logo', ['tipo' => 'imagen', 'archivo_mime' => 'image/jpeg', 'datos' => ['media_id' => 'media.IMG']]);

    correrTurno($conversacion);

    $imagen->refresh();
    Storage::disk('local')->assertExists($imagen->archivo_ruta);

    $contenido = end($this->claude->peticiones[0]['mensajes'])['content'];
    expect($contenido[0])->toMatchArray(['type' => 'image'])
        ->and($contenido[0]['source'])->toMatchArray(['type' => 'base64', 'mediaType' => 'image/jpeg', 'data' => base64_encode('bytes-de-foto')])
        ->and($contenido[1]['text'])->toContain('archivo_id '.$imagen->id)->toContain('este es mi logo');
});

it('un archivo de más de 10 MB no se descarga y el agente recibe el aviso', function () {
    Http::fake([
        'graph.facebook.com/*/media.GRANDE' => Http::response(['url' => 'https://lookaside.fbsbx.com/x', 'mime_type' => 'application/pdf', 'file_size' => 20 * 1024 * 1024]),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.salida']]]),
    ]);
    $conversacion = conversacion();
    $archivo = mensajeCliente($conversacion, '', ['tipo' => 'documento', 'archivo_mime' => 'application/pdf', 'datos' => ['media_id' => 'media.GRANDE']]);

    correrTurno($conversacion);

    expect($archivo->fresh())->archivo_ruta->toBeNull()->tipo->toBe('otro')->texto->toContain('no puedo abrir');
});

it('Configuración muestra la sección de WhatsApp al administrador sin enseñar los secretos', function () {
    $admin = User::factory()->administrador()->create();
    numeroWhatsapp($admin, ['phone_number_id' => '5555555555', 'token' => 'token-largo-secreto-abcd']);

    $this->actingAs($admin)->get('/configuracion')
        ->assertOk()
        ->assertSee('Webhook para Meta')
        ->assertSee('…abcd')
        ->assertDontSee('token-largo-secreto-abcd');

    $this->actingAs($this->user)->get('/configuracion')->assertOk()->assertDontSee('Webhook para Meta');
});

it('desde la ficha del cliente abre su conversación o el envío de una plantilla', function () {
    $cliente = Cliente::factory()->for($this->user)->create(['telefono' => '4497654321']);

    $this->actingAs($this->user)->get("/whatsapp/cliente/{$cliente->id}")->assertOk()->assertSee('Mandar una plantilla');

    $conversacion = conversacion(['wa_id' => '524497654321', 'telefono_10' => '4497654321']);
    $this->actingAs($this->user)->get("/whatsapp/cliente/{$cliente->id}")->assertRedirect("/whatsapp/{$conversacion->id}");
});

it('el aviso de privacidad es público, indexable y menciona a los proveedores', function () {
    $this->get('/privacidad')
        ->assertOk()
        ->assertSee('Aviso de privacidad')
        ->assertSee('Meta Platforms')
        ->assertSee('Anthropic')
        ->assertSee('index, follow', false);
});
