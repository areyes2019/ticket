<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

#[Signature('catalogos-sat:actualizar')]
#[Description('Descarga los catálogos del SAT (phpcfdi/resources-sat-catalogs) y reemplaza las tablas locales')]
class ActualizarCatalogosSat extends Command
{
    public const URL_BASE = 'https://raw.githubusercontent.com/phpcfdi/resources-sat-catalogs/master/database/data/';

    /**
     * Tabla local => archivo de origen, columna de la descripción e índice de
     * las columnas en el INSERT de origen.
     *
     * @var array<string, array{archivo: string, columna: string, texto: int, vigencia_hasta: int}>
     */
    private const CATALOGOS = [
        'sat_claves_prod_serv' => [
            'archivo' => 'cfdi_40_productos_servicios.sql',
            'columna' => 'descripcion',
            'texto' => 1,
            'vigencia_hasta' => 6,
        ],
        'sat_claves_unidad' => [
            'archivo' => 'cfdi_40_claves_unidades.sql',
            'columna' => 'nombre',
            'texto' => 1,
            'vigencia_hasta' => 5,
        ],
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Se descarga y se interpreta todo antes de tocar la base: si algo
        // falla, las tablas conservan los datos anteriores.
        $registros = [];

        try {
            foreach (self::CATALOGOS as $tabla => $catalogo) {
                $this->line("Descargando {$catalogo['archivo']}…");

                $contenido = Http::timeout(120)->get(self::URL_BASE.$catalogo['archivo'])->throw()->body();
                $registros[$tabla] = $this->interpretar($contenido, $catalogo);

                if ($registros[$tabla] === []) {
                    $this->error("{$catalogo['archivo']} no contiene claves; no se actualizó nada.");

                    return self::FAILURE;
                }
            }
        } catch (ConnectionException|RequestException $excepcion) {
            $this->error('No se pudieron descargar los catálogos: '.$excepcion->getMessage());

            return self::FAILURE;
        }

        DB::transaction(function () use ($registros) {
            foreach ($registros as $tabla => $filas) {
                DB::table($tabla)->delete();

                foreach (array_chunk($filas, 1000) as $bloque) {
                    DB::table($tabla)->insert($bloque);
                }
            }
        });

        foreach ($registros as $tabla => $filas) {
            $this->info(number_format(count($filas))." claves cargadas en {$tabla}.");
        }

        return self::SUCCESS;
    }

    /**
     * Convierte el volcado SQL de origen (una línea INSERT por clave) en filas
     * de la tabla local, omitiendo las claves cuya vigencia ya terminó.
     *
     * @param  array{archivo: string, columna: string, texto: int, vigencia_hasta: int}  $catalogo
     * @return list<array<string, string>>
     */
    private function interpretar(string $contenido, array $catalogo): array
    {
        $hoy = now()->toDateString();
        $filas = [];

        // Solo \n y \r\n: \R sin el modificador u también corta en el byte 0x85,
        // que aparece dentro de letras UTF-8 como la Å de «Ångström».
        foreach (preg_split('/\r?\n/', $contenido) as $linea) {
            if (! preg_match('/^INSERT INTO \w+ VALUES\((.*)\);$/', $linea, $coincidencia)) {
                continue;
            }

            $valores = str_getcsv($coincidencia[1], ',', "'", '');
            $vigenciaHasta = $valores[$catalogo['vigencia_hasta']] ?? '';

            if ($vigenciaHasta !== '' && $vigenciaHasta < $hoy) {
                continue;
            }

            $filas[] = [
                'clave' => $valores[0],
                $catalogo['columna'] => $valores[$catalogo['texto']],
            ];
        }

        return $filas;
    }
}
