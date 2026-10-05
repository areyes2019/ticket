<?php

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\DatoBancario;
use App\Models\Factura;
use App\Models\OrdenCompra;
use App\Models\User;
use App\Services\Cotizaciones\GeneradorPdfCotizacion;
use App\Services\Facturacion\GeneradorPdfFactura;
use App\Services\OrdenesCompra\GeneradorPdfOrdenCompra;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Datos bancarios en la cotización (027).
 */
const CLABE_BBVA = '012180001234567899';
const CLABE_SANTANDER = '014180009876543213';

/**
 * @param  array<string, mixed>  $cambios
 * @return array<string, mixed>
 */
function datosBanco(array $cambios = []): array
{
    return [
        'nombre_banco' => 'BBVA',
        'beneficiario' => 'Rosa Martínez',
        'numero_cuenta' => '0123456789',
        'tarjeta' => '',
        'clabe' => CLABE_BBVA,
        'visible_en_cotizaciones' => '1',
        ...$cambios,
    ];
}

function pngBanco(int $ancho, int $alto, bool $transparente = false): UploadedFile
{
    $lienzo = imagecreatetruecolor($ancho, $alto);
    imagealphablending($lienzo, false);
    imagesavealpha($lienzo, true);
    imagefill($lienzo, 0, 0, imagecolorallocatealpha($lienzo, 0, 80, 160, $transparente ? 127 : 0));
    ob_start();
    imagepng($lienzo);

    return UploadedFile::fake()->createWithContent('logo.png', ob_get_clean());
}

function htmlCotizacionBancos(Cotizacion $cotizacion): string
{
    return view('cotizaciones.pdf', app(GeneradorPdfCotizacion::class)->datos($cotizacion->fresh()))->render();
}

/**
 * Cotización creada por la ruta real, que es la que toma la foto.
 */
function crearCotizacionConBancos(User $user, Cliente $cliente): Cotizacion
{
    test()->actingAs($user)->post('/cotizaciones', [
        'cliente_id' => $cliente->id,
        'descuento_global_tipo' => '',
        'descuento_global_valor' => '',
        'lineas' => [[
            'articulo_id' => '', 'cantidad' => '1', 'descripcion' => 'Sello', 'modelo' => '', 'precio_unitario' => '100.00',
            'descuento_tipo' => '', 'descuento_valor' => '', 'tasa_iva' => '16',
        ]],
    ])->assertSessionHasNoErrors();

    return Cotizacion::query()->latest('id')->firstOrFail();
}

beforeEach(function () {
    Storage::fake('local');

    $this->admin = User::factory()->administrador()->create();
    $this->user = User::factory()->create();
    $this->cliente = Cliente::factory()->for($this->user)->create();
});

describe('acceso', function () {
    it('pide iniciar sesión', function (string $metodo, string $ruta) {
        $dato = DatoBancario::factory()->create();

        $this->call($metodo, str_replace('{id}', (string) $dato->id, $ruta))->assertRedirect(route('login'));
    })->with([
        ['GET', '/configuracion/datos-bancarios/crear'],
        ['POST', '/configuracion/datos-bancarios'],
        ['PUT', '/configuracion/datos-bancarios/{id}'],
        ['DELETE', '/configuracion/datos-bancarios/{id}'],
        ['GET', '/configuracion/datos-bancarios/{id}/logo'],
    ]);

    it('solo el administrador los administra y ve la sección', function (string $metodo, string $ruta) {
        $dato = DatoBancario::factory()->create();

        $this->actingAs($this->user)->call($metodo, str_replace('{id}', (string) $dato->id, $ruta), datosBanco())->assertForbidden();
    })->with([
        ['GET', '/configuracion/datos-bancarios/crear'],
        ['POST', '/configuracion/datos-bancarios'],
        ['GET', '/configuracion/datos-bancarios/{id}/editar'],
        ['PUT', '/configuracion/datos-bancarios/{id}'],
        ['DELETE', '/configuracion/datos-bancarios/{id}'],
        ['PATCH', '/configuracion/datos-bancarios/{id}/visible'],
        ['PATCH', '/configuracion/datos-bancarios/{id}/mover/arriba'],
        ['GET', '/configuracion/datos-bancarios/{id}/logo'],
    ]);

    it('la sección aparece en Configuración solo para el administrador', function () {
        DatoBancario::factory()->create(['nombre_banco' => 'Banorte']);

        $this->actingAs($this->admin)->get('/configuracion')->assertOk()->assertSee('Datos bancarios')->assertSee('Banorte');
        $this->actingAs($this->user)->get('/configuracion')->assertOk()->assertDontSee('Datos bancarios');
    });

    it('sin bancos explica para qué sirve la sección', function () {
        $this->actingAs($this->admin)->get('/configuracion')->assertSee('Todavía no hay bancos.');
    });
});

describe('captura', function () {
    it('da de alta un banco al final de la lista', function () {
        DatoBancario::factory()->create();

        $this->actingAs($this->admin)->post('/configuracion/datos-bancarios', datosBanco())
            ->assertRedirect(route('configuracion.edit').'#datos-bancarios')
            ->assertSessionHas('exito', 'Banco BBVA agregado.');

        expect(DatoBancario::query()->latest('id')->first())
            ->nombre_banco->toBe('BBVA')
            ->beneficiario->toBe('Rosa Martínez')
            ->numero_cuenta->toBe('0123456789')
            ->tarjeta->toBeNull()
            ->clabe->toBe(CLABE_BBVA)
            ->visible_en_cotizaciones->toBeTrue()
            ->orden->toBe(2);
    });

    it('rechaza un banco sin nombre', function () {
        $this->actingAs($this->admin)->post('/configuracion/datos-bancarios', datosBanco(['nombre_banco' => '']))
            ->assertSessionHasErrors('nombre_banco');

        expect(DatoBancario::count())->toBe(0);
    });

    it('pide al menos un número', function () {
        $this->actingAs($this->admin)->post('/configuracion/datos-bancarios', datosBanco(['numero_cuenta' => ' ', 'tarjeta' => '', 'clabe' => '']))
            ->assertSessionHasErrors(['numeros' => 'Captura al menos un número de cuenta, tarjeta o CLABE.']);

        expect(DatoBancario::count())->toBe(0);
    });

    it('valida el dígito verificador de la CLABE', function () {
        $this->actingAs($this->admin)->post('/configuracion/datos-bancarios', datosBanco(['clabe' => '012180001234567890']))
            ->assertSessionHasErrors(['clabe' => 'La CLABE no es válida: revisa que esté bien escrita.']);

        $this->actingAs($this->admin)->post('/configuracion/datos-bancarios', datosBanco(['clabe' => '032180000118359719']))
            ->assertSessionHasNoErrors();
    });

    it('rechaza una CLABE de 17 o 19 dígitos', function (string $clabe) {
        $this->actingAs($this->admin)->post('/configuracion/datos-bancarios', datosBanco(['clabe' => $clabe]))
            ->assertSessionHasErrors(['clabe' => 'La CLABE debe tener exactamente 18 dígitos.']);
    })->with(['17' => '01218000123456789', '19' => '0121800012345678990']);

    it('limpia espacios y guiones y conserva el cero inicial', function () {
        $this->actingAs($this->admin)->post('/configuracion/datos-bancarios', datosBanco([
            'numero_cuenta' => '012-345 6789',
            'tarjeta' => '4152 3133 1234 5678',
            'clabe' => '012 180 00123456789 9',
        ]))->assertSessionHasNoErrors();

        expect(DatoBancario::sole())
            ->numero_cuenta->toBe('0123456789')
            ->tarjeta->toBe('4152313312345678')
            ->clabe->toBe(CLABE_BBVA);
    });

    it('rechaza tarjetas y cuentas fuera de su largo', function () {
        $this->actingAs($this->admin)->post('/configuracion/datos-bancarios', datosBanco(['tarjeta' => '41523133', 'numero_cuenta' => '12345']))
            ->assertSessionHasErrors(['tarjeta', 'numero_cuenta']);
    });

    it('permite repetir el banco', function () {
        $this->actingAs($this->admin)->post('/configuracion/datos-bancarios', datosBanco());
        $this->actingAs($this->admin)->post('/configuracion/datos-bancarios', datosBanco(['numero_cuenta' => '9876543210']))->assertSessionHasNoErrors();

        expect(DatoBancario::where('nombre_banco', 'BBVA')->count())->toBe(2);
    });

    it('edita, oculta y elimina', function () {
        $dato = DatoBancario::factory()->create(['nombre_banco' => 'HSBC']);

        $this->actingAs($this->admin)->get("/configuracion/datos-bancarios/{$dato->id}/editar")->assertOk()->assertSee('HSBC');

        $this->actingAs($this->admin)->put("/configuracion/datos-bancarios/{$dato->id}", datosBanco(['nombre_banco' => 'Santander', 'clabe' => CLABE_SANTANDER, 'visible_en_cotizaciones' => '1']))
            ->assertSessionHasNoErrors();
        expect($dato->fresh())->nombre_banco->toBe('Santander')->clabe->toBe(CLABE_SANTANDER);

        $this->actingAs($this->admin)->patch("/configuracion/datos-bancarios/{$dato->id}/visible");
        expect($dato->fresh()->visible_en_cotizaciones)->toBeFalse();

        $this->actingAs($this->admin)->get('/configuracion')->assertSee('No se muestra en cotizaciones');

        $this->actingAs($this->admin)->delete("/configuracion/datos-bancarios/{$dato->id}")
            ->assertSessionHas('exito', 'Banco Santander eliminado.');
        expect(DatoBancario::count())->toBe(0);
    });

    it('sube y baja intercambiando con el vecino, sin moverse en los extremos', function () {
        [$a, $b, $c] = DatoBancario::factory()->count(3)->create()->all();
        $orden = fn () => DatoBancario::query()->ordenados()->pluck('id')->all();

        $this->actingAs($this->admin)->patch("/configuracion/datos-bancarios/{$c->id}/mover/arriba");
        expect($orden())->toBe([$a->id, $c->id, $b->id]);

        $this->actingAs($this->admin)->patch("/configuracion/datos-bancarios/{$a->id}/mover/abajo");
        expect($orden())->toBe([$c->id, $a->id, $b->id]);

        $this->actingAs($this->admin)->patch("/configuracion/datos-bancarios/{$c->id}/mover/arriba");
        $this->actingAs($this->admin)->patch("/configuracion/datos-bancarios/{$b->id}/mover/abajo");
        expect($orden())->toBe([$c->id, $a->id, $b->id])
            ->and(DatoBancario::query()->ordenados()->pluck('orden')->all())->toBe([1, 2, 3]);
    });
});

describe('logo', function () {
    it('se guarda como icono WEBP de 64 puntos de lado largo', function () {
        $this->actingAs($this->admin)->post('/configuracion/datos-bancarios', datosBanco(['logo' => pngBanco(1000, 500)]))
            ->assertSessionHasNoErrors();

        $dato = DatoBancario::sole();
        $contenido = Storage::disk('local')->get($dato->logo_ruta);
        [$ancho, $alto, $tipo] = getimagesizefromstring($contenido);

        expect($dato->logo_ruta)->toStartWith('datos-bancarios/'.$dato->id.'-')->toEndWith('.webp')
            ->and([$ancho, $alto, $tipo])->toBe([64, 32, IMAGETYPE_WEBP]);

        $this->actingAs($this->admin)->get("/configuracion/datos-bancarios/{$dato->id}/logo")
            ->assertOk()->assertHeader('Content-Type', 'image/webp');
    });

    it('no amplía un logo chico y conserva la transparencia', function () {
        $this->actingAs($this->admin)->post('/configuracion/datos-bancarios', datosBanco(['logo' => pngBanco(40, 20, transparente: true)]));

        $imagen = imagecreatefromstring(Storage::disk('local')->get(DatoBancario::sole()->logo_ruta));

        expect([imagesx($imagen), imagesy($imagen)])->toBe([40, 20])
            ->and((imagecolorat($imagen, 5, 5) >> 24) & 0x7F)->toBe(127);
    });

    it('rechaza lo que no es imagen aunque termine en .png, y no crea el banco', function () {
        $this->actingAs($this->admin)->post('/configuracion/datos-bancarios', datosBanco(['logo' => UploadedFile::fake()->createWithContent('logo.png', 'no soy una imagen')]))
            ->assertSessionHasErrors('logo');

        expect(DatoBancario::count())->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('reemplazar borra el anterior y cambia la versión; quitar lo borra', function () {
        $this->actingAs($this->admin)->post('/configuracion/datos-bancarios', datosBanco(['logo' => pngBanco(100, 100)]));
        $dato = DatoBancario::sole();
        $primero = $dato->logo_ruta;

        $this->actingAs($this->admin)->put("/configuracion/datos-bancarios/{$dato->id}", datosBanco(['logo' => pngBanco(200, 100)]));
        $dato->refresh();

        expect($dato->logo_ruta)->not->toBe($primero)
            ->and(Storage::disk('local')->exists($primero))->toBeFalse()
            ->and(Storage::disk('local')->exists($dato->logo_ruta))->toBeTrue();

        $this->actingAs($this->admin)->get('/configuracion')->assertSee('v='.$dato->logo_version, false);

        $this->actingAs($this->admin)->put("/configuracion/datos-bancarios/{$dato->id}", datosBanco(['quitar_logo' => '1']));

        expect($dato->fresh()->logo_ruta)->toBeNull()
            ->and(Storage::disk('local')->allFiles())->toBe([]);

        $this->actingAs($this->admin)->get("/configuracion/datos-bancarios/{$dato->id}/logo")->assertNotFound();
    });

    it('eliminar el banco conserva el archivo del logo', function () {
        $this->actingAs($this->admin)->post('/configuracion/datos-bancarios', datosBanco(['logo' => pngBanco(100, 100)]));
        $dato = DatoBancario::sole();

        $this->actingAs($this->admin)->delete("/configuracion/datos-bancarios/{$dato->id}");

        expect(Storage::disk('local')->exists($dato->logo_ruta))->toBeTrue();
    });
});

describe('foto en la cotización', function () {
    it('congela los visibles en su orden y sin los ocultos', function () {
        DatoBancario::factory()->create(['nombre_banco' => 'Santander', 'orden' => 2]);
        DatoBancario::factory()->create(['nombre_banco' => 'BBVA', 'orden' => 1]);
        DatoBancario::factory()->oculto()->create(['nombre_banco' => 'HSBC', 'orden' => 3]);

        $cotizacion = crearCotizacionConBancos($this->user, $this->cliente);

        expect(collect($cotizacion->datos_bancarios)->pluck('nombre_banco')->all())->toBe(['BBVA', 'Santander'])
            ->and(array_keys($cotizacion->datos_bancarios[0]))->toBe(DatoBancario::CAMPOS_FOTO);
    });

    it('sin bancos visibles la foto queda vacía y el PDF sale sin bloque', function () {
        DatoBancario::factory()->oculto()->create();

        $cotizacion = crearCotizacionConBancos($this->user, $this->cliente);

        expect($cotizacion->datos_bancarios)->toBe([])
            ->and(htmlCotizacionBancos($cotizacion))->not->toContain('Datos bancarios');
    });

    it('una cotización anterior a 027 (sin foto) se imprime sin bloque', function () {
        DatoBancario::factory()->create();
        $cotizacion = Cotizacion::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);

        expect($cotizacion->datos_bancarios)->toBeNull()
            ->and(htmlCotizacionBancos($cotizacion))->not->toContain('Datos bancarios');
    });

    it('cambiar, ocultar o eliminar el banco no cambia una cotización ya creada, ni al editarla', function () {
        $dato = DatoBancario::factory()->create(['nombre_banco' => 'BBVA', 'clabe' => CLABE_BBVA]);
        $cotizacion = crearCotizacionConBancos($this->user, $this->cliente);

        $dato->update(['clabe' => CLABE_SANTANDER, 'visible_en_cotizaciones' => false]);
        $this->actingAs($this->user)->put("/cotizaciones/{$cotizacion->id}", [
            'cliente_id' => $this->cliente->id, 'descuento_global_tipo' => '', 'descuento_global_valor' => '',
            'lineas' => [['articulo_id' => '', 'cantidad' => '3', 'descripcion' => 'Sello', 'modelo' => '', 'precio_unitario' => '100.00', 'descuento_tipo' => '', 'descuento_valor' => '', 'tasa_iva' => '16']],
        ])->assertSessionHasNoErrors();
        $dato->delete();

        expect(htmlCotizacionBancos($cotizacion))->toContain('CLABE: '.CLABE_BBVA)->not->toContain(CLABE_SANTANDER);
    });

    it('duplicar toma los datos vigentes, no los del original', function () {
        $dato = DatoBancario::factory()->create(['clabe' => CLABE_BBVA]);
        $original = crearCotizacionConBancos($this->user, $this->cliente);
        $dato->update(['clabe' => CLABE_SANTANDER]);

        $this->actingAs($this->user)->post("/cotizaciones/{$original->id}/duplicar", ['cliente_id' => $this->cliente->id]);

        $copia = Cotizacion::whereKeyNot($original->id)->sole();

        expect($copia->datos_bancarios[0]['clabe'])->toBe(CLABE_SANTANDER)
            ->and($original->fresh()->datos_bancarios[0]['clabe'])->toBe(CLABE_BBVA);
    });
});

describe('PDF', function () {
    it('imprime el bloque bajo el folio con un renglón por dato capturado', function () {
        DatoBancario::factory()->create(['nombre_banco' => 'BBVA', 'beneficiario' => 'Rosa Martínez', 'numero_cuenta' => '0123456789', 'tarjeta' => '4152313312345678', 'clabe' => CLABE_BBVA, 'orden' => 1]);
        DatoBancario::factory()->create(['nombre_banco' => 'Santander', 'beneficiario' => null, 'numero_cuenta' => null, 'tarjeta' => null, 'clabe' => CLABE_SANTANDER, 'orden' => 2]);

        $html = htmlCotizacionBancos(crearCotizacionConBancos($this->user, $this->cliente));
        $santander = Str::after($html, '<strong>Santander</strong>');

        expect($html)->toContain('Datos bancarios', 'Rosa Martínez', 'Cta: 0123456789', 'Tarjeta: 4152313312345678', 'CLABE: '.CLABE_BBVA)
            ->and(strpos($html, 'Datos bancarios'))->toBeGreaterThan(strpos($html, 'COT-0001'))
            ->and(strpos($html, '<strong>BBVA</strong>'))->toBeLessThan(strpos($html, '<strong>Santander</strong>'))
            ->and(Str::before($santander, '</div>'))->toContain('CLABE: '.CLABE_SANTANDER)->not->toContain('Cta:', 'Tarjeta:')
            ->and($html)->not->toContain('data:image/webp');
    });

    it('incrusta el icono a 5 mm de alto; si el archivo falta sale sin icono y avisa en el log', function () {
        $this->actingAs($this->admin)->post('/configuracion/datos-bancarios', datosBanco(['logo' => pngBanco(200, 100)]));
        $cotizacion = crearCotizacionConBancos($this->user, $this->cliente);

        expect(htmlCotizacionBancos($cotizacion))->toContain('data:image/webp;base64,', 'height: 5mm; width: 10mm;');

        Storage::disk('local')->delete(DatoBancario::sole()->logo_ruta);
        Log::spy();

        expect(htmlCotizacionBancos($cotizacion))->toContain('<strong>BBVA</strong>')->not->toContain('data:image/webp');
        Log::shouldHaveReceived('warning')->once();
    });

    it('eliminar el banco no quita su icono de las cotizaciones ya creadas', function () {
        $this->actingAs($this->admin)->post('/configuracion/datos-bancarios', datosBanco(['logo' => pngBanco(100, 100)]));
        $cotizacion = crearCotizacionConBancos($this->user, $this->cliente);

        $this->actingAs($this->admin)->delete('/configuracion/datos-bancarios/'.DatoBancario::sole()->id);

        expect(htmlCotizacionBancos($cotizacion))->toContain('data:image/webp;base64,');
    });

    it('el PDF con bancos y logo se genera', function () {
        $this->actingAs($this->admin)->post('/configuracion/datos-bancarios', datosBanco(['logo' => pngBanco(100, 50)]));
        $cotizacion = crearCotizacionConBancos($this->user, $this->cliente);

        expect(app(GeneradorPdfCotizacion::class)->contenido($cotizacion))->toStartWith('%PDF');
    });

    it('factura y orden de compra no imprimen datos bancarios', function () {
        DatoBancario::factory()->create();
        $factura = Factura::factory()->for($this->cliente)->timbrada()->conLinea()->create(['user_id' => $this->user->id]);
        $orden = OrdenCompra::factory()->conLinea()->create();

        expect(view('facturas.pdf', app(GeneradorPdfFactura::class)->datos($factura))->render())->not->toContain('Datos bancarios', 'CLABE')
            ->and(view('ordenes-compra.pdf', app(GeneradorPdfOrdenCompra::class)->datos($orden))->render())->not->toContain('Datos bancarios', 'CLABE');
    });
});
