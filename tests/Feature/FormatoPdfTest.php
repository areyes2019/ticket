<?php

use App\Enums\EstadoFactura;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Emisor;
use App\Models\Factura;
use App\Models\OrdenCompra;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\Cotizaciones\GeneradorPdfCotizacion;
use App\Services\Documentos\LogoDocumento;
use App\Services\Facturacion\GeneradorPdfFactura;
use App\Services\OrdenesCompra\GeneradorPdfOrdenCompra;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

/**
 * Formato unificado de los PDF (026). Las pruebas de contenido renderizan la
 * vista con los datos del generador; las de "se genera" pasan por dompdf.
 */
function htmlCotizacion(Cotizacion $cotizacion): string
{
    return view('cotizaciones.pdf', app(GeneradorPdfCotizacion::class)->datos($cotizacion))->render();
}

function htmlOrden(OrdenCompra $orden): string
{
    return view('ordenes-compra.pdf', app(GeneradorPdfOrdenCompra::class)->datos($orden))->render();
}

function htmlFactura(Factura $factura): string
{
    return view('facturas.pdf', app(GeneradorPdfFactura::class)->datos($factura))->render();
}

function emisorCompleto(): Emisor
{
    return Emisor::create([
        'nombre' => 'SELLO PRONTO DISTRIBUCIONES',
        'rfc' => 'SPD010101AB1',
        'regimen_fiscal' => '612',
        'domicilio' => '38024, Celaya, Guanajuato',
        'correo' => 'ventas@prosello.com.mx',
        'telefono' => '+524611234567',
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->cliente = Cliente::factory()->for($this->user)->create(['razon_social' => 'Peña y Cía']);
    $this->proveedor = Proveedor::factory()->for($this->user)->create([
        'nombre_comercial' => 'Tintas del Bajío', 'rfc' => null, 'nombre_contacto' => null, 'correo' => null, 'telefono' => null,
    ]);
    $this->cotizacion = Cotizacion::factory()->for($this->cliente)->conLinea()->create(['user_id' => $this->user->id]);
    $this->orden = OrdenCompra::factory()->for($this->proveedor)->conLinea()->create();
    $this->factura = Factura::factory()->for($this->cliente)->timbrada()->conLinea()->create(['user_id' => $this->user->id]);
});

describe('contenido', function () {
    it('los tres imprimen emisor, folio y el logo incrustado', function () {
        emisorCompleto();

        expect(htmlCotizacion($this->cotizacion))
            ->toContain('SELLO PRONTO DISTRIBUCIONES', 'SPD010101AB1', '612 – ', 'COT-0001', 'data:image/png;base64,', 'no es un comprobante fiscal')
            ->and(htmlOrden($this->orden))
            ->toContain('SELLO PRONTO DISTRIBUCIONES', $this->orden->folio_formateado, 'data:image/png;base64,')
            ->and(htmlFactura($this->factura))
            ->toContain($this->factura->folioVisible(), 'data:image/png;base64,', 'representación impresa de un CFDI');
    });

    it('con el emisor vacío cotización y orden usan el nombre de la aplicación', function () {
        expect(htmlCotizacion($this->cotizacion))->toContain(e(config('app.name')))
            ->and(htmlOrden($this->orden))->toContain(e(config('app.name')));
    });

    it('la factura imprime lo fiscal del timbrado y el contacto de Configuración', function () {
        emisorCompleto();

        expect(htmlFactura($this->factura))
            ->toContain('ESCUELA KEMPER URGATE', 'EKU9003173C9', '38024, Celaya, Guanajuato', '+524611234567')
            ->not->toContain('SPD010101AB1');
    });

    it('la factura timbrada lleva el timbre completo y el estado vigente', function () {
        $html = htmlFactura($this->factura);

        expect($html)
            ->toContain($this->factura->uuid_fiscal, '00001000000504465028', 'Sello digital del CFDI', 'Sello del SAT', 'Cadena original', 'data:image/png;base64,', 'Vigente')
            ->not->toContain('CANCELADA');
    });

    it('la factura cancelada conserva el timbre, el estado en rojo y la marca de agua', function () {
        $this->factura->forceFill(['estado' => EstadoFactura::Cancelada])->save();

        expect(htmlFactura($this->factura))
            ->toContain($this->factura->uuid_fiscal, 'Sello del SAT', 'class="cancelada"', 'CANCELADA');
    });

    it('si el QR no se puede generar imprime la URL de verificación y lo registra', function () {
        Log::spy();
        // Más datos de los que caben en un QR: la librería lanza excepción.
        $this->factura->forceFill(['url_verificacion_sat' => 'https://verificacfdi.facturaelectronica.sat.gob.mx/?x='.str_repeat('A', 5000)])->save();

        $html = htmlFactura($this->factura);

        expect($html)->toContain('Verificación SAT', 'https://verificacfdi.facturaelec')
            ->and(app(GeneradorPdfFactura::class)->contenido($this->factura))->toStartWith('%PDF');
        Log::shouldHaveReceived('error')->withArgs(fn (string $mensaje) => str_contains($mensaje, 'QR'));
    });

    it('la orden de un proveedor sin datos omite los renglones vacíos', function () {
        $html = htmlOrden($this->orden);

        expect($html)->toContain('Tintas del Bajío', 'Costo unitario')
            ->not->toContain('Contacto ')
            ->not->toContain('Precio unitario');
    });

    it('una línea libre deja la clave SAT vacía y un artículo borrado conserva la suya', function () {
        expect(htmlCotizacion($this->cotizacion))->not->toContain('44121600');

        $articulo = Articulo::factory()
            ->for(Catalogo::factory()->for($this->proveedor)->create(['user_id' => $this->user->id]))
            ->create(['user_id' => $this->user->id, 'clave_prod_serv' => '44121600']);
        $this->cotizacion->lineas->first()->update(['articulo_id' => $articulo->id]);
        $articulo->delete();

        expect(htmlCotizacion($this->cotizacion->fresh()))->toContain('44121600', 'Precio unitario')
            ->and(htmlOrden($this->orden))->not->toContain('44121600');
    });

    it('solo la factura tiene columna Unidad', function () {
        expect(htmlFactura($this->factura))->toContain('<th>Unidad</th>')
            ->and(htmlCotizacion($this->cotizacion))->not->toContain('<th>Unidad</th>')
            ->and(htmlOrden($this->orden))->not->toContain('<th>Unidad</th>');
    });

    it('la caja monoespaciada corta antes de escapar', function () {
        $html = Blade::render('<x-pdf.mono-box :texto="$texto" />', ['texto' => str_repeat('a', 105).'&'.str_repeat('b', 394)]);
        $fragmentos = explode('<br>', trim(strip_tags($html, '<br>')));

        expect($fragmentos)->toHaveCount(5)
            ->and(mb_strlen(html_entity_decode($fragmentos[0])))->toBe(110)
            ->and($html)->toContain('&amp;')->not->toContain('&am<br>');
    });

    it('imprime acentos y eñes en el PDF real', function () {
        $texto = (new Parser)->parseContent(app(GeneradorPdfCotizacion::class)->contenido($this->cotizacion))->getText();

        expect($texto)->toContain('COTIZACIÓN', 'Régimen', 'Peña y Cía');
    });
});

describe('se genera', function () {
    it('los tres salen con la tabla emisor vacía', function () {
        expect(app(GeneradorPdfCotizacion::class)->contenido($this->cotizacion))->toStartWith('%PDF')
            ->and(app(GeneradorPdfOrdenCompra::class)->contenido($this->orden))->toStartWith('%PDF')
            ->and(app(GeneradorPdfFactura::class)->contenido($this->factura))->toStartWith('%PDF');
    });

    it('los tres salen sin el archivo del logo y queda un aviso en el log', function () {
        Log::spy();
        app()->instance(LogoDocumento::class, new LogoDocumento(base_path('no-existe.png')));

        expect(htmlCotizacion($this->cotizacion))->not->toContain('data:image/png;base64,')
            ->and(app(GeneradorPdfCotizacion::class)->contenido($this->cotizacion))->toStartWith('%PDF')
            ->and(app(GeneradorPdfOrdenCompra::class)->contenido($this->orden))->toStartWith('%PDF')
            ->and(app(GeneradorPdfFactura::class)->contenido($this->factura))->toStartWith('%PDF');
        Log::shouldHaveReceived('warning')->once();
    });

    it('ajusta el logo a la caja sin deformarlo', function () {
        expect(LogoDocumento::ajustar(600, 360))->toBe(['ancho_mm' => 50.0, 'alto_mm' => 30.0])
            ->and(LogoDocumento::ajustar(300, 300))->toBe(['ancho_mm' => 30.0, 'alto_mm' => 30.0])
            ->and(LogoDocumento::ajustar(1000, 100))->toBe(['ancho_mm' => 50.0, 'alto_mm' => 5.0])
            ->and((new LogoDocumento)->medidas())->toBe(['ancho_mm' => 50.0, 'alto_mm' => 30.0]);
    });
});

describe('emisor en Configuración', function () {
    beforeEach(function () {
        $this->admin = User::factory()->administrador()->create();
        $this->datos = [
            'nombre' => 'SELLO PRONTO DISTRIBUCIONES', 'rfc' => 'EKU9003173C9', 'regimen_fiscal' => '612',
            'domicilio' => '38024, Celaya', 'correo' => 'ventas@prosello.com.mx', 'telefono' => '461 123 4567',
        ];
    });

    it('crea la fila la primera vez y la actualiza después, sin duplicarla', function () {
        $this->actingAs($this->admin)->put('/configuracion/emisor', $this->datos)
            ->assertRedirect(route('configuracion.edit').'#emisor')
            ->assertSessionHas('exito');
        $this->actingAs($this->admin)->put('/configuracion/emisor', [...$this->datos, 'domicilio' => 'Otro']);

        expect(Emisor::count())->toBe(1)
            ->and(Emisor::actual())->domicilio->toBe('Otro')->telefono->toBe('+524611234567')
            ->and(Emisor::actual()->estaCompleto())->toBeTrue();
    });

    it('rechaza un RFC inválido y un régimen inexistente', function () {
        $this->actingAs($this->admin)->put('/configuracion/emisor', [...$this->datos, 'rfc' => 'XXX', 'regimen_fiscal' => '999'])
            ->assertSessionHasErrors(['rfc', 'regimen_fiscal']);

        expect(Emisor::count())->toBe(0);
    });

    it('solo el administrador puede editarlo y ver la sección', function () {
        $this->actingAs($this->user)->put('/configuracion/emisor', $this->datos)->assertForbidden();
        $this->actingAs($this->user)->get('/configuracion')->assertOk()->assertDontSee('Datos del emisor');
        $this->actingAs($this->admin)->get('/configuracion')->assertOk()->assertSee('Datos del emisor');
    });

    it('el aviso aparece en cotización y orden mientras el emisor esté incompleto', function () {
        $this->actingAs($this->user)->get("/cotizaciones/{$this->cotizacion->id}")->assertSee('sin datos del emisor');
        $this->actingAs($this->user)->get("/ordenes-compra/{$this->orden->id}")->assertSee('sin datos del emisor');
        $this->actingAs($this->user)->get("/facturas/{$this->factura->id}")->assertDontSee('sin datos del emisor');

        emisorCompleto();

        $this->actingAs($this->user)->get("/cotizaciones/{$this->cotizacion->id}")->assertDontSee('sin datos del emisor');
    });
});
