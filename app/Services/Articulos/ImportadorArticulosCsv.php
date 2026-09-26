<?php

namespace App\Services\Articulos;

use App\Enums\ObjetoImpuesto;
use App\Http\Requests\ArticuloRequest;
use App\Models\Articulo;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * Da de alta artículos desde un CSV, fila por fila: las filas válidas se
 * insertan y las inválidas se reportan sin abortar el archivo.
 */
class ImportadorArticulosCsv
{
    private const BOM_UTF8 = "\xEF\xBB\xBF";

    /**
     * @return array{importados: int, errores: list<array{fila: int, modelo: string, motivo: string}>}
     *
     * @throws ArchivoCsvInvalido
     */
    public function importar(string $ruta, User $usuario, Proveedor $proveedor): array
    {
        $archivo = $this->abrirComoUtf8($ruta);
        $encabezado = $this->leerEncabezado($archivo);

        $reporte = ['importados' => 0, 'errores' => []];
        $fila = 1;

        while (($celdas = fgetcsv($archivo, null, ',', '"', '')) !== false) {
            $fila++;

            if ($this->estaVacia($celdas)) {
                continue;
            }

            $datos = $this->normalizar($this->combinar($encabezado, $celdas));
            $validador = Validator::make(
                [...$datos, 'proveedor_id' => $proveedor->id],
                ArticuloRequest::reglas($usuario->id, $proveedor->id),
                $this->mensajes($datos),
                array_combine(Articulo::COLUMNAS_CSV, Articulo::COLUMNAS_CSV),
            );

            if ($validador->fails()) {
                $reporte['errores'][] = [
                    'fila' => $fila,
                    'modelo' => $datos['modelo'],
                    'motivo' => implode(' ', $validador->errors()->all()),
                ];

                continue;
            }

            $usuario->articulos()->create($validador->validated());
            $reporte['importados']++;
        }

        fclose($archivo);

        return $reporte;
    }

    /**
     * Devuelve el archivo como flujo UTF-8 sin BOM. Acepta UTF-8 (con o sin
     * BOM) y Windows-1252, detectado por el contenido: es lo que guarda Excel
     * en español según la opción que elija el usuario.
     *
     * @return resource
     */
    private function abrirComoUtf8(string $ruta)
    {
        $contenido = (string) file_get_contents($ruta);

        if (str_starts_with($contenido, self::BOM_UTF8)) {
            $contenido = substr($contenido, strlen(self::BOM_UTF8));
        }

        if (! mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }

        $flujo = fopen('php://temp', 'r+');
        fwrite($flujo, $contenido);
        rewind($flujo);

        return $flujo;
    }

    /**
     * @param  resource  $archivo
     * @return list<string>
     *
     * @throws ArchivoCsvInvalido
     */
    private function leerEncabezado($archivo): array
    {
        $encabezado = fgetcsv($archivo, null, ',', '"', '');

        if ($encabezado === false || $this->estaVacia($encabezado)) {
            throw new ArchivoCsvInvalido('El archivo está vacío.');
        }

        $encabezado = array_map(fn (?string $columna) => mb_strtolower(trim((string) $columna)), $encabezado);
        $faltantes = array_diff(Articulo::COLUMNAS_CSV, $encabezado);

        if ($faltantes !== []) {
            throw new ArchivoCsvInvalido('Al encabezado del archivo le faltan las columnas: '.implode(', ', $faltantes).'.');
        }

        return $encabezado;
    }

    /**
     * @param  list<string|null>  $celdas
     */
    private function estaVacia(array $celdas): bool
    {
        return array_filter($celdas, fn (?string $celda) => trim((string) $celda) !== '') === [];
    }

    /**
     * Toma solo las columnas esperadas, por nombre; las demás se ignoran.
     *
     * @param  list<string>  $encabezado
     * @param  list<string|null>  $celdas
     * @return array<string, string>
     */
    private function combinar(array $encabezado, array $celdas): array
    {
        $datos = [];

        foreach (Articulo::COLUMNAS_CSV as $columna) {
            $datos[$columna] = trim((string) ($celdas[array_search($columna, $encabezado, true)] ?? ''));
        }

        return $datos;
    }

    /**
     * Deshace las reescrituras que hace una hoja de cálculo al guardar. No
     * relaja la validación: un valor que sigue sin corresponder se rechaza.
     *
     * @param  array<string, string>  $datos
     * @return array<string, string>
     */
    private function normalizar(array $datos): array
    {
        $datos['clave_unidad'] = mb_strtoupper($datos['clave_unidad']);

        // Excel y Google Sheets leen "02" como número y lo guardan como "2".
        if (preg_match('/^\d$/', $datos['objeto_imp'])) {
            $datos['objeto_imp'] = '0'.$datos['objeto_imp'];
        }

        return $datos;
    }

    /**
     * En las columnas de lista cerrada el motivo nombra la columna y el valor
     * recibido; el mensaje genérico no dice qué corregir en la hoja.
     *
     * @param  array<string, string>  $datos
     * @return array<string, string>
     */
    private function mensajes(array $datos): array
    {
        $claves = implode(', ', array_column(ObjetoImpuesto::cases(), 'value'));

        return [
            ...ArticuloRequest::mensajes(),
            'objeto_imp.enum' => "objeto_imp \"{$datos['objeto_imp']}\" no es un valor válido ({$claves}).",
            'clave_prod_serv.exists' => "clave_prod_serv \"{$datos['clave_prod_serv']}\" no existe en el catálogo del SAT.",
            'clave_unidad.exists' => "clave_unidad \"{$datos['clave_unidad']}\" no existe en el catálogo del SAT.",
        ];
    }
}
