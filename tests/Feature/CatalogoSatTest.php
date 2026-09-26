<?php

use App\Console\Commands\ActualizarCatalogosSat;
use App\Models\SatClaveProdServ;
use App\Models\SatClaveUnidad;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Volcado con el mismo formato que publica phpcfdi/resources-sat-catalogs.
 *
 * @param  list<list<string|int>>  $filas
 */
function volcado(string $tabla, array $filas): string
{
    $lineas = ['PRAGMA foreign_keys=OFF;', 'BEGIN TRANSACTION;'];

    foreach ($filas as $fila) {
        $valores = array_map(fn ($valor) => is_int($valor) ? $valor : "'".str_replace("'", "''", $valor)."'", $fila);
        $lineas[] = "INSERT INTO {$tabla} VALUES(".implode(',', $valores).');';
    }

    return implode("\n", [...$lineas, 'COMMIT;'])."\n";
}

describe('sugerencias', function () {
    beforeEach(function () {
        sembrarCatalogosSat();
        $this->actingAs(User::factory()->create());
    });

    it('busca por prefijo de la clave', function () {
        $this->getJson('/catalogos-sat/claves-prod-serv?q=441216')
            ->assertOk()
            ->assertExactJson([
                ['clave' => '44121600', 'descripcion' => 'Suministros de escritorio'],
                ['clave' => '44121604', 'descripcion' => 'Sellos de goma'],
            ]);
    });

    it('busca por parte de la descripción', function () {
        $this->getJson('/catalogos-sat/claves-prod-serv?q=goma')
            ->assertExactJson([['clave' => '44121604', 'descripcion' => 'Sellos de goma']]);

        $this->getJson('/catalogos-sat/claves-unidad?q=pieza')
            ->assertExactJson([['clave' => 'H87', 'descripcion' => 'Pieza']]);
    });

    it('responde vacío sin término', function () {
        $this->getJson('/catalogos-sat/claves-unidad?q=%20')->assertExactJson([]);
    });

    it('devuelve como máximo 20 sugerencias', function () {
        SatClaveUnidad::insert(array_map(fn (int $i) => ['clave' => sprintf('Z%02d', $i), 'nombre' => "Unidad {$i}"], range(1, 30)));

        $this->getJson('/catalogos-sat/claves-unidad?q=unidad')->assertJsonCount(20);
    });
});

describe('catalogos-sat:actualizar', function () {
    it('reemplaza los catálogos y omite las claves vencidas', function () {
        SatClaveUnidad::insert(['clave' => 'OLD', 'nombre' => 'Clave anterior']);

        Http::fake([
            ActualizarCatalogosSat::URL_BASE.'cfdi_40_productos_servicios.sql' => Http::response(volcado('cfdi_40_productos_servicios', [
                ['44121604', 'Sellos de goma', '', '', '', '2022-01-01', '', 1, ''],
                ['10101506', "Caballos 'criollos'", '', '', '', '2022-01-01', '', 1, 'Equinos, Potros'],
                ['99999999', 'Clave vencida', '', '', '', '2022-01-01', '2023-01-01', 1, ''],
            ])),
            ActualizarCatalogosSat::URL_BASE.'cfdi_40_claves_unidades.sql' => Http::response(volcado('cfdi_40_claves_unidades', [
                ['H87', 'Pieza', 'Unidad de conteo', '', '2022-01-01', '', ''],
                ['A11', 'Ångström', '', '', '2022-01-01', '', 'Å'],
            ])),
        ]);

        $this->artisan('catalogos-sat:actualizar')
            ->expectsOutputToContain('2 claves cargadas en sat_claves_prod_serv.')
            ->expectsOutputToContain('2 claves cargadas en sat_claves_unidad.')
            ->assertSuccessful();

        expect(SatClaveProdServ::orderBy('clave')->pluck('descripcion', 'clave')->all())->toBe([
            '10101506' => "Caballos 'criollos'",
            '44121604' => 'Sellos de goma',
        ])->and(SatClaveUnidad::orderBy('clave')->pluck('nombre', 'clave')->all())->toBe(['A11' => 'Ångström', 'H87' => 'Pieza']);
    });

    it('conserva los datos anteriores si falla la descarga', function () {
        sembrarCatalogosSat();

        Http::fake([
            ActualizarCatalogosSat::URL_BASE.'cfdi_40_productos_servicios.sql' => Http::response(volcado('cfdi_40_productos_servicios', [
                ['44121604', 'Sellos de goma', '', '', '', '2022-01-01', '', 1, ''],
            ])),
            ActualizarCatalogosSat::URL_BASE.'cfdi_40_claves_unidades.sql' => Http::response('', 500),
        ]);

        $this->artisan('catalogos-sat:actualizar')->assertFailed();

        expect(SatClaveProdServ::count())->toBe(4)
            ->and(SatClaveUnidad::count())->toBe(3);
    });
});
