<?php

use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Existencia;
use App\Models\Factura;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Cabeceras con las que Axios pide el fragmento de la búsqueda dinámica.
 *
 * @return array<string, string>
 */
function cabecerasAjax(): array
{
    return ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];
}

/**
 * Un puñado de claves SAT reales, para no depender de la descarga completa
 * del catálogo (catalogos-sat:actualizar).
 */
function sembrarCatalogosSat(): void
{
    DB::table('sat_claves_prod_serv')->insert([
        ['clave' => '01010101', 'descripcion' => 'No existe en el catálogo'],
        ['clave' => '44121600', 'descripcion' => 'Suministros de escritorio'],
        ['clave' => '44121604', 'descripcion' => 'Sellos de goma'],
        ['clave' => '60121000', 'descripcion' => 'Pinturas y medios y aplicadores'],
    ]);

    DB::table('sat_claves_unidad')->insert([
        ['clave' => 'H87', 'nombre' => 'Pieza'],
        ['clave' => 'E48', 'nombre' => 'Unidad de servicio'],
        ['clave' => 'KGM', 'nombre' => 'Kilogramo'],
    ]);
}

/**
 * Respuesta de éxito de facturapi.io con los nombres y formatos reales vistos
 * en el sandbox: cfdi_version numérico y stamp.date en hora de México sin zona.
 *
 * @return array<string, mixed>
 */
function respuestaTimbrado(array $cambios = []): array
{
    return [
        'id' => '64f0c0ffee0000000000abcd',
        'uuid' => '5E2D6AFF-2DD7-43D1-83D3-14C1ACA396D9',
        'series' => 'A',
        'folio_number' => 123,
        'status' => 'valid',
        'cancellation_status' => 'none',
        'cfdi_version' => 4,
        'total' => 116,
        'verification_url' => 'https://verificacfdi.facturaelectronica.sat.gob.mx/default.aspx?id=5E2D6AFF',
        'stamp' => [
            'signature' => 'SELLO-CFDI',
            'sat_signature' => 'SELLO-SAT',
            'sat_cert_number' => '00001000000504465028',
            'date' => '2026-09-28T06:30:00',
            'complement_string' => '||1.1|5E2D6AFF|2026-09-28T06:30:00|SELLO|00001000000504465028||',
        ],
        ...$cambios,
    ];
}

/**
 * Artículo de un catálogo propio del usuario, listo para una línea de factura.
 *
 * @param  array<string, mixed>  $atributos
 */
function articuloFacturable(User $user, array $atributos = []): Articulo
{
    $catalogo = Catalogo::factory()->conDescuento(10)->conUtilidad(50)
        ->for(Proveedor::factory()->for($user))
        ->create(['user_id' => $user->id]);

    return Articulo::factory()->for($catalogo)->create(['user_id' => $user->id, 'precio_proveedor' => '100.00', ...$atributos]);
}

/**
 * Agrega una línea a una cotización, factura u orden de compra, con o sin
 * artículo. Los importes no importan para el inventario.
 */
function agregarLinea(Model $documento, ?Articulo $articulo, int $cantidad): void
{
    $datos = [
        'orden' => $documento->lineas()->count() + 1,
        'articulo_id' => $articulo?->id,
        'cantidad' => $cantidad,
        'descripcion' => $articulo->nombre ?? 'Flete',
        'modelo' => $articulo->modelo ?? 'LIBRE',
        'precio_unitario' => '100.00',
        'tasa_iva' => '16',
        'importe' => '100.00',
        'iva_importe' => '16.00',
    ];

    if ($documento instanceof Factura) {
        $datos += ['clave_prod_serv' => $articulo->clave_prod_serv, 'clave_unidad' => $articulo->clave_unidad, 'objeto_imp' => $articulo->objeto_imp];
    }

    $documento->lineas()->forceCreate($datos);
}

/**
 * Marca el artículo "en existencias" con estos números, sin movimiento (como
 * si viniera de antes).
 */
function marcarExistencia(Articulo $articulo, int $existencia, int $faltante = 0, int $minimo = 0, ?int $maximo = null): Existencia
{
    $fila = new Existencia;
    $fila->forceFill(['articulo_id' => $articulo->id, 'existencia' => $existencia, 'faltante_pendiente' => $faltante, 'minimo' => $minimo, 'maximo' => $maximo])->save();

    return $fila;
}
