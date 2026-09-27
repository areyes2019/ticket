<?php

use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\User;
use App\Services\Articulos\CargadorImagenesArticulos;
use App\Services\Articulos\ProcesadorImagenArticulo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->catalogo = Catalogo::factory()->create();
    $this->usuario = $this->catalogo->user;
});

/**
 * Un .zip con esas entradas (nombre => contenido).
 *
 * @param  array<string, string>  $entradas
 */
function zipCon(array $entradas): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive;
    $zip->open($ruta, ZipArchive::OVERWRITE);

    foreach ($entradas as $nombre => $contenido) {
        $zip->addFromString($nombre, $contenido);
    }

    $zip->close();

    return new UploadedFile($ruta, 'fotos.zip', 'application/zip', null, true);
}

function contenidoImagen(string $nombre = 'foto.jpg', int $ancho = 100, int $alto = 100): string
{
    return UploadedFile::fake()->image($nombre, $ancho, $alto)->getContent();
}

/**
 * @param  list<UploadedFile>|UploadedFile  $archivos
 */
function subirImagenes(User $usuario, Catalogo $catalogo, array|UploadedFile $archivos)
{
    return test()->actingAs($usuario)
        ->followingRedirects()
        ->post('/articulos/imagenes', ['catalogo_id' => $catalogo->id, ...(is_array($archivos) ? ['archivos' => $archivos] : ['archivo' => $archivos])]);
}

describe('acceso', function () {
    it('pide iniciar sesión', function (string $metodo, string $ruta) {
        $this->call($metodo, $ruta)->assertRedirect(route('login'));
    })->with([
        ['GET', '/articulos/imagenes'],
        ['POST', '/articulos/imagenes'],
        ['GET', '/articulos/1/imagen'],
    ]);

    it('saca a un usuario suspendido', function () {
        $this->actingAs(User::factory()->suspendido()->create())
            ->get('/articulos/imagenes')
            ->assertRedirect(route('login'));
    });

    it('enlaza la pantalla desde el listado de artículos', function () {
        $this->actingAs($this->usuario)
            ->get('/articulos')
            ->assertSee(route('articulos.imagenes'))
            ->assertSee('Subir imágenes');
    });
});

describe('entrega de la imagen', function () {
    it('entrega la imagen al dueño como WEBP con caché privada', function () {
        $articulo = Articulo::factory()->for($this->catalogo)->conImagen()->create();

        $respuesta = $this->actingAs($this->usuario)->get(route('articulos.imagen', $articulo));

        $respuesta->assertOk()->assertHeader('Content-Type', 'image/webp');
        expect($respuesta->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=604800');
    });

    it('responde 404 a otro usuario', function () {
        $articulo = Articulo::factory()->for($this->catalogo)->conImagen()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('articulos.imagen', $articulo))
            ->assertNotFound();
    });

    it('responde 404 si el artículo no tiene imagen', function () {
        $articulo = Articulo::factory()->for($this->catalogo)->create();

        $this->actingAs($this->usuario)->get(route('articulos.imagen', $articulo))->assertNotFound();
    });

    it('conserva y entrega la imagen de un artículo eliminado', function () {
        $articulo = Articulo::factory()->for($this->catalogo)->conImagen()->create();

        $this->actingAs($this->usuario)->delete(route('articulos.destroy', $articulo));

        Storage::disk('local')->assertExists($articulo->imagen_ruta);
        $this->get(route('articulos.imagen', $articulo))->assertOk();
    });
});

describe('procesamiento', function () {
    it('reduce la imagen a 1200 puntos de lado largo y la guarda como WEBP con nombre propio', function () {
        $articulo = Articulo::factory()->for($this->catalogo)->create(['modelo' => 'A-1']);

        subirImagenes($this->usuario, $this->catalogo, [UploadedFile::fake()->image('A-1.jpg', 3000, 2000)])
            ->assertSee('1 imagen asociada.');

        $ruta = $articulo->fresh()->imagen_ruta;
        [$ancho, $alto, $tipo] = getimagesizefromstring(Storage::disk('local')->get($ruta));

        expect($ruta)->toMatch('/^articulos\/'.$articulo->id.'-[a-z0-9]{8}\.webp$/')
            ->and([$ancho, $alto, $tipo])->toBe([1200, 800, IMAGETYPE_WEBP]);
    });

    it('no amplía una imagen chica', function () {
        $articulo = Articulo::factory()->for($this->catalogo)->create(['modelo' => 'A-1']);

        subirImagenes($this->usuario, $this->catalogo, [UploadedFile::fake()->image('A-1.png', 800, 600)]);

        expect(array_slice(getimagesizefromstring(Storage::disk('local')->get($articulo->fresh()->imagen_ruta)), 0, 2))->toBe([800, 600]);
    });

    it('conserva la transparencia', function () {
        $articulo = Articulo::factory()->for($this->catalogo)->create(['modelo' => 'A-1']);
        $lienzo = imagecreatetruecolor(2000, 1000);
        imagealphablending($lienzo, false);
        imagesavealpha($lienzo, true);
        imagefill($lienzo, 0, 0, imagecolorallocatealpha($lienzo, 255, 0, 0, 127));
        ob_start();
        imagepng($lienzo);

        subirImagenes($this->usuario, $this->catalogo, [UploadedFile::fake()->createWithContent('A-1.png', ob_get_clean())]);

        $guardada = imagecreatefromstring(Storage::disk('local')->get($articulo->fresh()->imagen_ruta));

        expect((imagecolorat($guardada, 10, 10) >> 24) & 0x7F)->toBe(127);
    });

    it('reporta un archivo que no es imagen aunque termine en .jpg, sin detener los demás', function () {
        Articulo::factory()->for($this->catalogo)->create(['modelo' => 'A-1']);
        $bueno = Articulo::factory()->for($this->catalogo)->create(['modelo' => 'A-2']);

        subirImagenes($this->usuario, $this->catalogo, [
            UploadedFile::fake()->createWithContent('A-1.jpg', 'no soy una imagen'),
            UploadedFile::fake()->image('A-2.jpg'),
        ])
            ->assertSee('1 imagen asociada.')
            ->assertSee('A-1.jpg')
            ->assertSee('no es una imagen JPG, PNG ni WEBP');

        expect($bueno->fresh()->tiene_imagen)->toBeTrue()
            ->and(Articulo::firstWhere('modelo', 'A-1')->imagen_ruta)->toBeNull();
    });

    it('reemplaza la imagen anterior y borra su archivo', function () {
        $articulo = Articulo::factory()->for($this->catalogo)->conImagen()->create(['modelo' => 'A-1']);
        $anterior = $articulo->imagen_ruta;

        subirImagenes($this->usuario, $this->catalogo, [UploadedFile::fake()->image('A-1.jpg')]);

        expect($articulo->fresh()->imagen_ruta)->not->toBe($anterior);
        Storage::disk('local')->assertMissing($anterior);
    });
});

describe('emparejamiento', function () {
    it('ignora mayúsculas, acentos, espacios, guiones y guiones bajos', function (string $archivo) {
        $articulo = Articulo::factory()->for($this->catalogo)->create(['modelo' => 'Sélló A-1234']);

        subirImagenes($this->usuario, $this->catalogo, [UploadedFile::fake()->image($archivo)])->assertSee('1 imagen asociada.');

        expect($articulo->fresh()->tiene_imagen)->toBeTrue();
    })->with(['sello a 1234.JPG', 'SELLO-A-1234.jpg', 'sello_a__1234.jpeg', ' Selló  A_1234 .png']);

    it('reporta el archivo sin artículo y no crea ninguno', function () {
        subirImagenes($this->usuario, $this->catalogo, [UploadedFile::fake()->image('B-77.jpg')])
            ->assertSee('0 imágenes asociadas.')
            ->assertSee('no hay ningún artículo con modelo &quot;B-77&quot; en este catálogo', false);

        expect(Articulo::count())->toBe(0);
        Storage::disk('local')->assertDirectoryEmpty('articulos');
    });

    it('no asigna un modelo ambiguo y lo reporta', function () {
        Articulo::factory()->for($this->catalogo)->count(2)->create(['modelo' => 'B-77']);

        subirImagenes($this->usuario, $this->catalogo, [UploadedFile::fake()->image('b 77.jpg')])
            ->assertSee('hay 2 artículos con modelo &quot;b 77&quot; en este catálogo', false);

        expect(Articulo::whereNotNull('imagen_ruta')->count())->toBe(0);
    });

    it('solo busca en el catálogo elegido y entre artículos no eliminados', function () {
        $otroCatalogo = Catalogo::factory()->create(['user_id' => $this->usuario->id]);
        $ajeno = Articulo::factory()->for($otroCatalogo)->create(['modelo' => 'A-1']);
        $eliminado = Articulo::factory()->for($this->catalogo)->create(['modelo' => 'A-2']);
        $eliminado->delete();

        subirImagenes($this->usuario, $this->catalogo, [UploadedFile::fake()->image('A-1.jpg'), UploadedFile::fake()->image('A-2.jpg')])
            ->assertSee('0 imágenes asociadas.');

        expect($ajeno->fresh()->tiene_imagen)->toBeFalse()
            ->and($eliminado->fresh()->tiene_imagen)->toBeFalse();
    });

    it('con dos archivos para el mismo artículo gana el último y reporta el otro', function () {
        $articulo = Articulo::factory()->for($this->catalogo)->create(['modelo' => 'A-1']);

        subirImagenes($this->usuario, $this->catalogo, [UploadedFile::fake()->image('A-1.jpg', 50, 50), UploadedFile::fake()->image('a_1.png', 70, 70)])
            ->assertSee('1 imagen asociada.')
            ->assertSee('otro archivo de esta carga se asignó al mismo artículo');

        expect(getimagesizefromstring(Storage::disk('local')->get($articulo->fresh()->imagen_ruta))[0])->toBe(70);
        expect(Storage::disk('local')->files('articulos'))->toHaveCount(1);
    });

    it('no reporta a los artículos que no recibieron imagen', function () {
        Articulo::factory()->for($this->catalogo)->create(['modelo' => 'A-1']);
        Articulo::factory()->for($this->catalogo)->create(['modelo' => 'SIN-FOTO']);

        subirImagenes($this->usuario, $this->catalogo, [UploadedFile::fake()->image('A-1.jpg')])
            ->assertSee('1 imagen asociada.')
            ->assertDontSee('SIN-FOTO');
    });

    it('reporta la imagen de más de 10 MB', function () {
        Articulo::factory()->for($this->catalogo)->create(['modelo' => 'A-1']);

        subirImagenes($this->usuario, $this->catalogo, [UploadedFile::fake()->image('A-1.jpg')->size(10241)])
            ->assertSee('pesa más de 10 MB');
    });
});

describe('límites y validación', function () {
    it('rechaza más de 20 archivos', function () {
        $archivos = array_map(fn (int $numero) => UploadedFile::fake()->image("{$numero}.jpg", 10, 10), range(1, 21));

        $this->actingAs($this->usuario)
            ->post('/articulos/imagenes', ['catalogo_id' => $this->catalogo->id, 'archivos' => $archivos])
            ->assertSessionHasErrors(['archivos' => 'Máximo 20 imágenes por envío; para más, usa un .zip.']);

        $this->postJson('/articulos/imagenes', ['catalogo_id' => $this->catalogo->id, 'archivos' => $archivos])
            ->assertUnprocessable();
    });

    it('rechaza imágenes y .zip en el mismo envío', function () {
        $this->actingAs($this->usuario)
            ->post('/articulos/imagenes', [
                'catalogo_id' => $this->catalogo->id,
                'archivos' => [UploadedFile::fake()->image('A-1.jpg')],
                'archivo' => zipCon(['A-2.jpg' => contenidoImagen()]),
            ])
            ->assertSessionHasErrors(['archivos' => 'Elige varias imágenes o un .zip, no los dos a la vez.']);
    });

    it('pide imágenes o un .zip', function () {
        $this->actingAs($this->usuario)
            ->post('/articulos/imagenes', ['catalogo_id' => $this->catalogo->id])
            ->assertSessionHasErrors(['archivos' => 'Elige varias imágenes o un .zip.']);
    });

    it('rechaza un catálogo ajeno', function () {
        $this->actingAs(User::factory()->create())
            ->post('/articulos/imagenes', ['catalogo_id' => $this->catalogo->id, 'archivos' => [UploadedFile::fake()->image('A-1.jpg')]])
            ->assertSessionHasErrors(['catalogo_id' => 'Selecciona uno de tus catálogos.']);
    });

    it('rechaza un .zip de más de 40 MB', function () {
        $this->actingAs($this->usuario)
            ->post('/articulos/imagenes', ['catalogo_id' => $this->catalogo->id, 'archivo' => UploadedFile::fake()->create('fotos.zip', 40961, 'application/zip')])
            ->assertSessionHasErrors('archivo');
    });
});

describe('zip', function () {
    it('procesa un .zip plano igual que la selección múltiple', function () {
        $articulo = Articulo::factory()->for($this->catalogo)->create(['modelo' => 'A-1']);

        subirImagenes($this->usuario, $this->catalogo, zipCon([
            'A-1.jpg' => contenidoImagen(),
            'notas.txt' => 'hola',
            'B-2.png' => contenidoImagen('B-2.png'),
        ]))
            ->assertSee('1 imagen asociada.')
            ->assertSee('no hay ningún artículo con modelo &quot;notas&quot; en este catálogo', false)
            ->assertSee('B-2.png');

        expect($articulo->fresh()->tiene_imagen)->toBeTrue();
    });

    it('rechaza completo un .zip con carpetas', function () {
        Articulo::factory()->for($this->catalogo)->create(['modelo' => 'A-1']);

        $this->actingAs($this->usuario)
            ->post('/articulos/imagenes', ['catalogo_id' => $this->catalogo->id, 'archivo' => zipCon([
                'A-1.jpg' => contenidoImagen(),
                'fotos/A-2.jpg' => contenidoImagen(),
            ])])
            ->assertSessionHasErrors(['archivo' => 'El .zip tiene carpetas dentro. Comprime seleccionando los archivos, no la carpeta.']);

        expect(Articulo::whereNotNull('imagen_ruta')->count())->toBe(0);
    });

    it('rechaza un .zip que se expande de más', function () {
        app()->bind(CargadorImagenesArticulos::class, fn () => new CargadorImagenesArticulos(app(ProcesadorImagenArticulo::class), 1024));

        $this->actingAs($this->usuario)
            ->post('/articulos/imagenes', ['catalogo_id' => $this->catalogo->id, 'archivo' => zipCon(['A-1.jpg' => str_repeat('a', 2048)])])
            ->assertSessionHasErrors(['archivo' => 'El contenido del .zip pesa más de 400 MB descomprimido; divídelo en varios.']);
    });
});

describe('respuestas', function () {
    it('vuelve a la pantalla con el reporte y el catálogo elegido', function () {
        $this->actingAs($this->usuario)
            ->post('/articulos/imagenes', ['catalogo_id' => $this->catalogo->id, 'archivos' => [UploadedFile::fake()->image('B-77.jpg')]])
            ->assertRedirect(route('articulos.imagenes'))
            ->assertSessionHas('reporte.asociadas', 0)
            ->assertSessionHas('_old_input.catalogo_id', $this->catalogo->id);
    });

    it('responde el reporte en JSON a las tandas', function () {
        Articulo::factory()->for($this->catalogo)->create(['modelo' => 'A-1']);

        $this->actingAs($this->usuario)
            ->post('/articulos/imagenes', ['catalogo_id' => $this->catalogo->id, 'archivos' => [
                UploadedFile::fake()->image('A-1.jpg'),
                UploadedFile::fake()->image('B-77.jpg'),
            ]], cabecerasAjax())
            ->assertOk()
            ->assertExactJson([
                'asociadas' => 1,
                'errores' => [['archivo' => 'B-77.jpg', 'motivo' => 'no hay ningún artículo con modelo "B-77" en este catálogo']],
            ]);
    });

    it('explica el resultado cuando ninguna imagen encontró su artículo', function () {
        subirImagenes($this->usuario, $this->catalogo, [UploadedFile::fake()->image('B-77.jpg')])
            ->assertSee('Ninguna imagen encontró su artículo en')
            ->assertSee(e($this->catalogo->etiqueta), false);
    });

    it('completa la carga a un catálogo vacío', function () {
        subirImagenes($this->usuario, $this->catalogo, [UploadedFile::fake()->image('B-77.jpg')])
            ->assertOk()
            ->assertSee('0 imágenes asociadas.');
    });

    it('envía el conteo de artículos de cada catálogo para el aviso de catálogo vacío', function () {
        Articulo::factory()->for($this->catalogo)->count(3)->create();
        $vacio = Catalogo::factory()->create(['user_id' => $this->usuario->id]);

        $this->actingAs($this->usuario)
            ->get('/articulos/imagenes')
            ->assertOk()
            ->assertViewHas('articulosPorCatalogo', fn (array $datos) => $datos[$this->catalogo->id]['articulos'] === 3
                && $datos[$vacio->id]['articulos'] === 0);
    });
});
