<?php

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
