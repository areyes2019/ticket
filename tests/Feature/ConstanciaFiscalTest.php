<?php

use App\Models\Cliente;
use App\Models\User;
use App\Services\Constancia\ConstanciaFiscalService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

const QR_FISICA = 'https://siat.sat.gob.mx/app/qr/faces/pages/mobile/validadorqr.jsf?D1=10&D2=1&D3=12345678901_GOPM800101AB1';
const QR_MORAL = 'https://siat.sat.gob.mx/app/qr/faces/pages/mobile/validadorqr.jsf?D1=10&D2=1&D3=98765432109_PME120315AB9';

beforeEach(function () {
    Http::preventStrayRequests();
});

function fixtureConstancia(string $nombre): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/constancias/'.$nombre));
}

function archivoConstancia(string $nombre): UploadedFile
{
    return UploadedFile::fake()->createWithContent($nombre, fixtureConstancia($nombre));
}

/**
 * El SAT responde con el HTML indicado.
 */
function satResponde(string $fixture): void
{
    Http::fake(['siat.sat.gob.mx/*' => Http::response(fixtureConstancia($fixture))]);
}

/**
 * El SAT no contesta (tiempo agotado). Devuelve el contador de intentos:
 * Http::fake() no registra las peticiones que lanzan excepción.
 */
function satCaido(): ArrayObject
{
    $intentos = new ArrayObject;

    Http::fake(['siat.sat.gob.mx/*' => function () use ($intentos) {
        $intentos->append(1);

        throw new ConnectionException('Tiempo agotado');
    }]);

    return $intentos;
}

/**
 * @param  array<string, mixed>  $datos
 */
function analizarConstancia(array $datos, ?User $usuario = null): TestResponse
{
    return test()->actingAs($usuario ?? User::factory()->create())
        ->post(route('clientes.constancia'), $datos, ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);
}

describe('acceso', function () {
    it('responde 401 sin sesión', function () {
        $this->post(route('clientes.constancia'), ['qr_url' => QR_FISICA], ['Accept' => 'application/json'])
            ->assertUnauthorized();
    });

    it('no deja pasar a un usuario suspendido', function () {
        satResponde('sat-fisica.html');

        analizarConstancia(['qr_url' => QR_FISICA], User::factory()->suspendido()->create())
            ->assertRedirect(route('login'));

        Http::assertNothingSent();
    });

    it('limita a 10 constancias por minuto', function () {
        satResponde('sat-fisica.html');
        $usuario = User::factory()->create();

        foreach (range(1, 10) as $intento) {
            analizarConstancia(['qr_url' => QR_FISICA], $usuario)->assertOk();
        }

        analizarConstancia(['qr_url' => QR_FISICA], $usuario)->assertTooManyRequests();
    });
});

describe('validación', function () {
    it('exige la constancia', function () {
        analizarConstancia([])->assertUnprocessable()->assertJsonValidationErrors('archivo');
    });

    it('rechaza un archivo de más de 10 MB', function () {
        analizarConstancia(['archivo' => UploadedFile::fake()->create('csf.pdf', 11 * 1024, 'application/pdf')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('archivo');
    });

    it('valida el tipo por su contenido y no por la extensión', function () {
        // Un archivo real: el falso de Laravel deduce el tipo por el nombre.
        $ruta = tempnam(sys_get_temp_dir(), 'csf');
        file_put_contents($ruta, "MZ\x90\x00\x03\x00\x00\x00ejecutable");

        analizarConstancia(['archivo' => new UploadedFile($ruta, 'csf.png', null, null, true)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('archivo');
    });
});

describe('camino oficial', function () {
    it('toma nombre y apellidos de una persona física', function () {
        satResponde('sat-fisica.html');

        analizarConstancia(['qr_url' => QR_FISICA])
            ->assertOk()
            ->assertJsonPath('fuente', 'SAT_QR_DIRECT')
            ->assertJsonPath('confianza', 'oficial')
            ->assertJsonPath('aviso', null)
            ->assertJsonPath('data.rfc', 'GOPM800101AB1')
            ->assertJsonPath('data.razon_social', 'MARIA FERNANDA GOMEZ PEREZ')
            ->assertJsonPath('data.codigo_postal_fiscal', '96535')
            ->assertJsonPath('data.direccion_comercial', 'JAGUARES 5208, COL CIUDAD OLMECA, COATZACOALCOS, VERACRUZ DE IGNACIO DE LA LLAVE');
    });

    it('toma la denominación de una persona moral', function () {
        satResponde('sat-moral.html');

        analizarConstancia(['qr_url' => QR_MORAL])
            ->assertOk()
            ->assertJsonPath('data.razon_social', 'PANDA CONNECT LOGISTICS')
            ->assertJsonPath('data.regimen_fiscal', '601')
            ->assertJsonPath('advertencias', []);
    });

    it('lee las filas aunque la página traiga cabecera XML, y reconoce la etiqueta CP', function () {
        satResponde('sat-fisica.html');

        analizarConstancia(['qr_url' => QR_FISICA])
            ->assertJsonPath('data.codigo_postal_fiscal', '96535')
            ->assertJsonPath('data.razon_social', 'MARIA FERNANDA GOMEZ PEREZ');
    });

    it('resuelve el régimen por su descripción y avisa si hay varios', function () {
        satResponde('sat-fisica.html');

        $respuesta = analizarConstancia(['qr_url' => QR_FISICA])
            ->assertJsonPath('data.regimen_fiscal', '612')
            ->assertJsonPath('data.regimenes_disponibles.0.clave', '612')
            ->assertJsonPath('data.regimenes_disponibles.1.clave', '605');

        expect($respuesta->json('advertencias'))->toContain(
            'El contribuyente tiene varios regímenes (612, 605); se propuso el 612. Confirma cuál corresponde a este cliente.'
        );
    });

    it('consulta la dirección canónica del validador y no la del QR', function () {
        satResponde('sat-fisica.html');

        analizarConstancia(['qr_url' => QR_FISICA.'&extra=1'])->assertOk();

        Http::assertSent(fn ($peticion) => $peticion->url() === QR_FISICA);
    });

    it('guarda en caché la respuesta del SAT', function () {
        satResponde('sat-fisica.html');
        $usuario = User::factory()->create();

        analizarConstancia(['qr_url' => QR_FISICA], $usuario)->assertOk();
        analizarConstancia(['qr_url' => QR_FISICA], $usuario)->assertOk();

        Http::assertSentCount(1);
    });
});

describe('dónde se encuentra el QR', function () {
    it('lo lee dentro del PDF sin ayuda del navegador', function () {
        satResponde('sat-fisica.html');

        analizarConstancia(['archivo' => archivoConstancia('csf-fisica.pdf')])
            ->assertOk()
            ->assertJsonPath('fuente', 'SAT_QR_DIRECT')
            ->assertJsonPath('data.rfc', 'GOPM800101AB1');

        Http::assertSent(fn ($peticion) => $peticion->url() === QR_FISICA);
    });

    it('elige el QR del validador aunque el del sello digital aparezca antes', function () {
        satResponde('sat-fisica.html');

        analizarConstancia(['archivo' => archivoConstancia('csf-con-qr.pdf')])
            ->assertOk()
            ->assertJsonPath('data.rfc', 'GOPM800101AB1');
    });

    it('lo lee de una foto', function () {
        satResponde('sat-fisica.html');

        analizarConstancia(['archivo' => archivoConstancia('csf-foto.png')])
            ->assertOk()
            ->assertJsonPath('data.rfc', 'GOPM800101AB1');
    });

    it('responde QR_NO_LEGIBLE si no hay QR', function () {
        $imagen = UploadedFile::fake()->image('foto.png', 300, 300);

        analizarConstancia(['archivo' => $imagen])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'QR_NO_LEGIBLE')
            ->assertJsonStructure(['mensaje']);

        Http::assertNothingSent();
    });

    it('rechaza un QR que apunta a otro sitio sin consultarlo', function (string $url) {
        analizarConstancia(['qr_url' => $url])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'QR_NO_OFICIAL');

        Http::assertNothingSent();
    })->with([
        'otro dominio' => 'https://ejemplo.com/validadorqr.jsf?D1=10&D2=1&D3=12345678901_GOPM800101AB1',
        'dominio que solo empieza igual' => 'https://sat.gob.mx.ejemplo.com/validadorqr.jsf?D1=10&D2=1&D3=12345678901_GOPM800101AB1',
        'http en vez de https' => 'http://siat.sat.gob.mx/app/qr/faces/pages/mobile/validadorqr.jsf?D1=10&D2=1&D3=12345678901_GOPM800101AB1',
    ]);
});

describe('SAT caído', function () {
    it('usa el texto del PDF y marca la caída', function () {
        satCaido();

        analizarConstancia(['archivo' => archivoConstancia('csf-fisica.pdf')])
            ->assertOk()
            ->assertJsonPath('fuente', 'PDF_TEXTO')
            ->assertJsonPath('confianza', 'documento')
            ->assertJsonPath('data.rfc', 'GOPM800101AB1')
            ->assertJsonPath('data.razon_social', 'MARIA FERNANDA GOMEZ PEREZ')
            ->assertJsonPath('data.codigo_postal_fiscal', '96535')
            ->assertJsonPath('data.regimen_fiscal', '605')
            ->assertJsonPath('data.direccion_comercial', 'JAGUARES 5208, COL CIUDAD OLMECA, COATZACOALCOS, VERACRUZ DE IGNACIO DE LA LLAVE');

        expect(Cache::has(ConstanciaFiscalService::CLAVE_CAIDA))->toBeTrue();
    });

    it('marca la caída también con un código de error HTTP', function () {
        Http::fake(['siat.sat.gob.mx/*' => Http::response('Servicio no disponible', 503)]);

        analizarConstancia(['archivo' => archivoConstancia('csf-fisica.pdf')])
            ->assertJsonPath('fuente', 'PDF_TEXTO');

        expect(Cache::has(ConstanciaFiscalService::CLAVE_CAIDA))->toBeTrue();
    });

    it('no vuelve a consultar al SAT durante la caída', function () {
        $intentos = satCaido();
        $usuario = User::factory()->create();

        analizarConstancia(['archivo' => archivoConstancia('csf-fisica.pdf')], $usuario)->assertOk();
        analizarConstancia(['archivo' => archivoConstancia('csf-moral.pdf')], $usuario)
            ->assertOk()
            ->assertJsonPath('fuente', 'PDF_TEXTO')
            ->assertJsonPath('data.razon_social', 'PANDA MEXICANA DE ENVIOS');

        expect($intentos)->toHaveCount(1);
    });

    it('precarga solo el RFC del QR si el documento no tiene texto', function (string $archivo) {
        satCaido();

        $respuesta = analizarConstancia(['archivo' => archivoConstancia($archivo)])
            ->assertOk()
            ->assertJsonPath('fuente', 'QR_RFC')
            ->assertJsonPath('confianza', 'parcial')
            ->assertJsonPath('data', ['rfc' => 'GOPM800101AB1']);

        expect($respuesta->json('aviso'))->toContain('No se pudo consultar al SAT');
    })->with(['csf-escaneada.pdf', 'csf-foto.png']);
});

describe('SAT con respuesta incompleta', function () {
    it('usa lo que vino, completa con el PDF y no marca la caída', function () {
        satResponde('sat-parcial.html');
        Log::spy();

        $respuesta = analizarConstancia(['archivo' => archivoConstancia('csf-fisica.pdf')])
            ->assertOk()
            ->assertJsonPath('fuente', 'SAT_QR_DIRECT')
            ->assertJsonPath('data.razon_social', 'MARIA FERNANDA GOMEZ PEREZ')
            ->assertJsonPath('data.codigo_postal_fiscal', '96535');

        expect(Cache::has(ConstanciaFiscalService::CLAVE_CAIDA))->toBeFalse()
            ->and($respuesta->json('advertencias'))->toContain('El SAT no devolvió régimen fiscal, código postal fiscal, dirección; se tomó del documento que subiste.');

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $mensaje, array $contexto) => $mensaje === 'Constancia: respuesta del SAT incompleta'
            && in_array('Ubicación fiscal', $contexto['etiquetas_sin_reconocer'], true));
    });

    it('la siguiente constancia vuelve a consultar al SAT', function () {
        satResponde('sat-parcial.html');
        $usuario = User::factory()->create();

        analizarConstancia(['qr_url' => QR_FISICA], $usuario)->assertOk();
        Cache::forget('csf:sat:'.sha1('12345678901_GOPM800101AB1'));
        analizarConstancia(['qr_url' => QR_FISICA], $usuario)->assertOk();

        Http::assertSentCount(2);
    });
});

describe('cliente existente', function () {
    it('avisa si el usuario ya tiene ese RFC', function () {
        satResponde('sat-fisica.html');
        $usuario = User::factory()->create();
        $cliente = Cliente::factory()->for($usuario)->create(['rfc' => 'GOPM800101AB1', 'razon_social' => 'MARIA GOMEZ']);

        analizarConstancia(['qr_url' => QR_FISICA], $usuario)
            ->assertJsonPath('cliente_existente.id', $cliente->id)
            ->assertJsonPath('cliente_existente.razon_social', 'MARIA GOMEZ')
            ->assertJsonPath('cliente_existente.url_editar', route('clientes.edit', $cliente));
    });

    it('no avisa por clientes de otro usuario ni eliminados', function () {
        satResponde('sat-fisica.html');
        $usuario = User::factory()->create();
        Cliente::factory()->create(['rfc' => 'GOPM800101AB1']);
        Cliente::factory()->for($usuario)->create(['rfc' => 'GOPM800101AB1'])->delete();

        analizarConstancia(['qr_url' => QR_FISICA], $usuario)->assertJsonPath('cliente_existente', null);
    });
});

describe('pantallas', function () {
    it('muestra la zona de carga al crear un cliente', function () {
        $this->actingAs(User::factory()->create())
            ->get(route('clientes.create'))
            ->assertOk()
            ->assertSee('data-constancia="'.route('clientes.constancia').'"', false)
            ->assertSee(asset('js/constancia-fiscal.js'));
    });

    it('identifica al cliente que se edita para no avisar de sí mismo', function () {
        $usuario = User::factory()->create();
        $cliente = Cliente::factory()->for($usuario)->create();

        $this->actingAs($usuario)
            ->get(route('clientes.edit', $cliente))
            ->assertOk()
            ->assertSee('data-cliente-id="'.$cliente->id.'"', false);
    });
});

it('no escribe nada en disco', function () {
    Storage::fake('local');
    satCaido();

    analizarConstancia(['archivo' => archivoConstancia('csf-fisica.pdf')])->assertOk();

    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});
