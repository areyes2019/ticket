<?php

use App\Enums\MotivoMovimientoInventario;
use App\Enums\ObjetoImpuesto;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Cotizacion;
use App\Models\CotizacionLinea;
use App\Models\User;
use App\Services\Inventario\RegistradorInventario;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    sembrarCatalogosSat();

    $this->catalogo = Catalogo::factory()->conDescuento(10)->conUtilidad(25)->create();
    $this->user = $this->catalogo->user;
});

/**
 * @param  array<string, mixed>  $atributos
 */
function articuloAClonar(Catalogo $catalogo, array $atributos = []): Articulo
{
    return Articulo::factory()->for($catalogo)->create(['nombre' => 'Sello redondo', ...$atributos]);
}

function clonar(User $user, Articulo $articulo): Articulo
{
    test()->actingAs($user)->post(route('articulos.clonar', $articulo));

    return Articulo::latest('id')->first();
}

describe('listado y ficha', function () {
    it('muestra "Clonar" en cada fila y da su dirección a la ficha', function () {
        $articulo = articuloAClonar($this->catalogo);
        $ruta = route('articulos.clonar', $articulo);

        $this->actingAs($this->user)->get(route('articulos.index'))
            ->assertOk()
            ->assertSee('action="'.$ruta.'"', false)
            ->assertSee('data-clonar="'.$ruta.'"', false)
            ->assertSee('aria-label="Clonar Sello redondo"', false)
            ->assertSee('form="ficha-clonar"', false);
    });
});

describe('clonar', function () {
    it('crea un solo artículo y abre la edición del clon con el aviso', function () {
        $original = articuloAClonar($this->catalogo);

        $respuesta = $this->actingAs($this->user)->post(route('articulos.clonar', $original));

        expect(Articulo::count())->toBe(2);
        $clon = Articulo::whereKeyNot($original->id)->sole();

        $respuesta->assertRedirect(route('articulos.edit', $clon))
            ->assertSessionHas('exito', 'Artículo clonado de «Sello redondo». Ajusta lo que necesites.');

        $this->get(route('articulos.edit', $clon))
            ->assertOk()
            ->assertSee('Artículo clonado de «Sello redondo». Ajusta lo que necesites.')
            ->assertSee('Sello redondo (copia)');
    });

    it('copia los datos del artículo y recalcula los mismos precios', function () {
        $original = articuloAClonar($this->catalogo, [
            'modelo' => 'R-45',
            'clave_prod_serv' => '44121604',
            'clave_unidad' => 'H87',
            'objeto_imp' => ObjetoImpuesto::NoObjeto,
            'precio_proveedor' => '200.00',
            'utilidad_porcentaje' => '40.00',
            'utilidad_distribuidor_porcentaje' => '15.00',
        ]);

        $clon = clonar($this->user, $original);

        expect($clon->id)->not->toBe($original->id)
            ->and($clon->user_id)->toBe($original->user_id)
            ->and($clon->only(['catalogo_id', 'proveedor_id', 'modelo', 'clave_prod_serv', 'clave_unidad', 'objeto_imp', 'precio_proveedor', 'utilidad_porcentaje', 'utilidad_distribuidor_porcentaje', 'costo_con_descuento', 'precio_unitario_sin_iva', 'precio_distribuidor_sin_iva']))
            ->toEqual($original->only(['catalogo_id', 'proveedor_id', 'modelo', 'clave_prod_serv', 'clave_unidad', 'objeto_imp', 'precio_proveedor', 'utilidad_porcentaje', 'utilidad_distribuidor_porcentaje', 'costo_con_descuento', 'precio_unitario_sin_iva', 'precio_distribuidor_sin_iva']));
    });

    it('deja heredando del catálogo las utilidades que el original hereda', function () {
        $clon = clonar($this->user, articuloAClonar($this->catalogo, ['utilidad_porcentaje' => null, 'utilidad_distribuidor_porcentaje' => null]));

        expect($clon->utilidad_porcentaje)->toBeNull()
            ->and($clon->utilidad_distribuidor_porcentaje)->toBeNull();
    });

    it('queda después del original en el orden por defecto', function () {
        $original = articuloAClonar($this->catalogo);

        expect(clonar($this->user, $original)->id)->toBeGreaterThan($original->id);
    });

    it('sigue guardado aunque no se guarde la edición', function () {
        $clon = clonar($this->user, articuloAClonar($this->catalogo));

        $this->get(route('articulos.index'))->assertOk()->assertSee('Sello redondo (copia)');

        expect(Articulo::find($clon->id))->not->toBeNull();
    });
});

describe('nombre del clon', function () {
    it('elige el primer sufijo libre', function () {
        $original = articuloAClonar($this->catalogo);

        expect(clonar($this->user, $original)->nombre)->toBe('Sello redondo (copia)')
            ->and(clonar($this->user, $original)->nombre)->toBe('Sello redondo (copia 2)')
            ->and(clonar($this->user, $original)->nombre)->toBe('Sello redondo (copia 3)');
    });

    it('no cuenta los eliminados', function () {
        $original = articuloAClonar($this->catalogo);
        articuloAClonar($this->catalogo, ['nombre' => 'Sello redondo (copia)'])->delete();

        expect(clonar($this->user, $original)->nombre)->toBe('Sello redondo (copia)');
    });

    it('no cuenta los de otro proveedor', function () {
        $original = articuloAClonar($this->catalogo);
        $otroCatalogo = Catalogo::factory()->create(['user_id' => $this->user->id]);
        articuloAClonar($otroCatalogo, ['nombre' => 'Sello redondo (copia)']);

        expect(clonar($this->user, $original)->nombre)->toBe('Sello redondo (copia)');
    });

    it('recorta el nombre, no el sufijo, para no pasar de 255', function () {
        $largo = str_repeat('ñ', 255);
        $original = articuloAClonar($this->catalogo, ['nombre' => $largo]);

        $nombre = clonar($this->user, $original)->nombre;

        expect(mb_strlen($nombre))->toBe(255)
            ->and($nombre)->toBe(str_repeat('ñ', 247).' (copia)');
    });

    it('copia el modelo sin cambios', function () {
        expect(clonar($this->user, articuloAClonar($this->catalogo, ['modelo' => 'R-45']))->modelo)->toBe('R-45');
    });
});

describe('lo que no se copia', function () {
    it('no copia la imagen y deja la del original intacta', function () {
        Storage::fake('local');
        $original = Articulo::factory()->for($this->catalogo)->conImagen()->create();
        $original->refresh();

        $clon = clonar($this->user, $original);

        expect($clon->imagen_ruta)->toBeNull()
            ->and($original->fresh()->imagen_ruta)->not->toBeNull();
        Storage::disk('local')->assertExists($original->imagen_ruta);
    });

    it('no copia existencias ni movimientos', function () {
        $original = articuloAClonar($this->catalogo);
        app(RegistradorInventario::class)->ajustar($original, 5, MotivoMovimientoInventario::EntradaInicial, null);

        $clon = clonar($this->user, $original);

        expect($clon->existencia()->exists())->toBeFalse()
            ->and($clon->movimientosInventario()->exists())->toBeFalse()
            ->and($original->existencia()->exists())->toBeTrue();
    });

    it('no se lleva el historial de documentos', function () {
        $original = articuloAClonar($this->catalogo);
        agregarLinea(Cotizacion::factory()->create(['user_id' => $this->user->id]), $original, 2);

        $clon = clonar($this->user, $original);

        expect(CotizacionLinea::sole()->articulo_id)->toBe($original->id)
            ->and(CotizacionLinea::where('articulo_id', $clon->id)->exists())->toBeFalse();
    });
});

describe('acceso', function () {
    it('pide iniciar sesión', function () {
        $original = articuloAClonar($this->catalogo);

        $this->post(route('articulos.clonar', $original))->assertRedirect(route('login'));

        expect(Articulo::count())->toBe(1);
    });

    it('responde 404 con un artículo ajeno', function () {
        $original = articuloAClonar($this->catalogo);

        $this->actingAs(User::factory()->create())
            ->post(route('articulos.clonar', $original))
            ->assertNotFound();

        expect(Articulo::count())->toBe(1);
    });

    it('responde 404 con un artículo eliminado', function () {
        $original = articuloAClonar($this->catalogo);
        $original->delete();

        $this->actingAs($this->user)
            ->post(route('articulos.clonar', $original))
            ->assertNotFound();

        expect(Articulo::withTrashed()->count())->toBe(1);
    });
});
