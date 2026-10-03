<?php

namespace App\Services\Clientes;

use App\Http\Requests\ClienteRequest;
use App\Http\Requests\Concerns\NormalizaTelefono;
use App\Models\User;
use App\Services\Articulos\ArchivoCsvInvalido;
use App\Services\Concerns\LeeArchivoCsv;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Da de alta clientes desde un CSV, fila por fila: las filas válidas se
 * insertan y las inválidas se reportan sin abortar el archivo.
 *
 * Entiende tanto los nombres de columna del sistema anterior (RazonSocial,
 * CP, RegimenFiscal, Calle, NumExterior...) como los de este sistema.
 */
class ImportadorClientesCsv
{
    use LeeArchivoCsv, NormalizaTelefono;

    /**
     * Columna del cliente => encabezados aceptados, ya normalizados (minúsculas,
     * sin acentos, espacios, guiones ni guiones bajos).
     */
    public const COLUMNAS = [
        'razon_social' => ['razonsocial', 'nombre', 'nombrefiscal'],
        'rfc' => ['rfc'],
        'regimen_fiscal' => ['regimenfiscal', 'regimen', 'regimenfisc'],
        'codigo_postal_fiscal' => ['cp', 'codigopostal', 'codigopostalfiscal'],
        'correo' => ['email', 'correo', 'correoelectronico'],
        'nombre_comercial' => ['nombrecomercial'],
        'nombre_contacto' => ['contacto', 'nombrecontacto'],
        'telefono' => ['telefono', 'tel'],
        'direccion_comercial' => ['direccioncomercial', 'direccion'],
        'calle' => ['calle'],
        'num_exterior' => ['numexterior', 'noexterior', 'numeroexterior'],
        'num_interior' => ['numinterior', 'nointerior', 'numerointerior'],
        'colonia' => ['colonia'],
        'ciudad' => ['ciudad', 'localidad'],
        'municipio' => ['municipio', 'delegacion'],
        'estado' => ['estado'],
        'pais' => ['pais'],
    ];

    /**
     * Columnas obligatorias => cómo se nombran en el mensaje de error.
     */
    public const OBLIGATORIAS = [
        'razon_social' => 'RazonSocial',
        'rfc' => 'RFC',
        'regimen_fiscal' => 'RegimenFiscal',
        'codigo_postal_fiscal' => 'CP',
    ];

    /**
     * @return array{importados: int, errores: list<array{fila: int, rfc: string, razon_social: string, motivo: string}>}
     *
     * @throws ArchivoCsvInvalido
     */
    public function importar(string $ruta, User $usuario): array
    {
        $archivo = $this->abrirComoUtf8($ruta);
        $separador = $this->detectarSeparador($archivo);
        $posiciones = $this->leerEncabezado($archivo, $separador);

        $reporte = ['importados' => 0, 'errores' => []];
        $fila = 1;

        while (($celdas = fgetcsv($archivo, null, $separador, '"', '')) !== false) {
            $fila++;

            if ($this->estaVacia($celdas)) {
                continue;
            }

            $datos = $this->normalizar($this->combinar($posiciones, $celdas));
            $validador = Validator::make($datos, ClienteRequest::reglas($usuario->id), ClienteRequest::mensajes(), ClienteRequest::atributos());

            if ($validador->fails()) {
                $reporte['errores'][] = [
                    'fila' => $fila,
                    'rfc' => $datos['rfc'],
                    'razon_social' => $datos['razon_social'],
                    'motivo' => implode(' ', $validador->errors()->all()),
                ];

                continue;
            }

            $usuario->clientes()->create($validador->validated());
            $reporte['importados']++;
        }

        fclose($archivo);

        return $reporte;
    }

    /**
     * Excel con configuración regional europea guarda el CSV con ";".
     *
     * @param  resource  $archivo
     */
    private function detectarSeparador($archivo): string
    {
        $primeraLinea = (string) fgets($archivo);
        rewind($archivo);

        return substr_count($primeraLinea, ';') > substr_count($primeraLinea, ',') ? ';' : ',';
    }

    /**
     * Ubica cada columna conocida en el encabezado; las desconocidas se ignoran.
     *
     * @param  resource  $archivo
     * @return array<string, int>
     *
     * @throws ArchivoCsvInvalido
     */
    private function leerEncabezado($archivo, string $separador): array
    {
        $encabezado = fgetcsv($archivo, null, $separador, '"', '');

        if ($encabezado === false || $this->estaVacia($encabezado)) {
            throw new ArchivoCsvInvalido('El archivo está vacío.');
        }

        $encabezado = array_map(
            fn (?string $columna) => preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii(trim((string) $columna)))),
            $encabezado,
        );

        $posiciones = [];

        foreach (self::COLUMNAS as $columna => $alias) {
            foreach ($alias as $nombre) {
                $indice = array_search($nombre, $encabezado, true);

                if ($indice !== false) {
                    $posiciones[$columna] = $indice;
                    break;
                }
            }
        }

        $faltantes = array_diff_key(self::OBLIGATORIAS, $posiciones);

        if ($faltantes !== []) {
            throw new ArchivoCsvInvalido('Al encabezado del archivo le faltan las columnas: '.implode(', ', $faltantes).'.');
        }

        return $posiciones;
    }

    /**
     * @param  array<string, int>  $posiciones
     * @param  list<string|null>  $celdas
     * @return array<string, string>
     */
    private function combinar(array $posiciones, array $celdas): array
    {
        $datos = [];

        foreach (array_keys(self::COLUMNAS) as $columna) {
            $datos[$columna] = isset($posiciones[$columna])
                ? trim(preg_replace('/\s+/u', ' ', (string) ($celdas[$posiciones[$columna]] ?? '')))
                : '';
        }

        return $datos;
    }

    /**
     * Deshace las reescrituras que hace una hoja de cálculo al guardar y arma
     * la dirección comercial con las columnas sueltas del sistema anterior.
     * No relaja la validación: un valor que sigue sin corresponder se rechaza.
     *
     * @param  array<string, string>  $datos
     * @return array<string, string|null>
     */
    private function normalizar(array $datos): array
    {
        $codigoPostal = preg_replace('/\D/', '', $datos['codigo_postal_fiscal']);

        // Excel lee "01000" como número y lo guarda como "1000".
        if ($codigoPostal !== '' && strlen($codigoPostal) < 5) {
            $codigoPostal = str_pad($codigoPostal, 5, '0', STR_PAD_LEFT);
        }

        // "601", "601 - General de Ley Personas Morales" o "601.0".
        preg_match('/^\d{3}/', $datos['regimen_fiscal'], $regimen);

        // Un solo correo: el sistema anterior permitía varios separados por ";" o ",".
        $correo = trim(preg_split('/[;,\s]+/', $datos['correo'])[0] ?? '');

        return [
            'rfc' => Str::upper(preg_replace('/[\s-]+/', '', $datos['rfc'])),
            'razon_social' => $datos['razon_social'],
            'regimen_fiscal' => $regimen[0] ?? $datos['regimen_fiscal'],
            'codigo_postal_fiscal' => $codigoPostal,
            'nombre_comercial' => $datos['nombre_comercial'] ?: null,
            'nombre_contacto' => $datos['nombre_contacto'] ?: null,
            'correo' => $correo !== '' ? Str::lower($correo) : null,
            'telefono' => $datos['telefono'] !== '' ? $this->normalizarTelefono($datos['telefono']) : null,
            'direccion_comercial' => $this->direccion($datos),
            'descuento_permanente' => '0',
        ];
    }

    /**
     * "Rio Guayalejo 1308 Int. 1-E, Col. Longoria, Reynosa, TAMAULIPAS". El
     * municipio se omite si repite la ciudad, y el país si es México.
     *
     * @param  array<string, string>  $datos
     */
    private function direccion(array $datos): ?string
    {
        if ($datos['direccion_comercial'] !== '') {
            return Str::limit($datos['direccion_comercial'], 255, '');
        }

        $calle = trim($datos['calle'].' '.$datos['num_exterior']);

        if ($datos['num_interior'] !== '') {
            $calle .= ' Int. '.$datos['num_interior'];
        }

        $esMexico = in_array(Str::lower(Str::ascii($datos['pais'])), ['mexico', 'mex', 'mx'], true);

        $partes = array_filter([
            trim($calle),
            $datos['colonia'] !== '' ? 'Col. '.$datos['colonia'] : '',
            $datos['ciudad'],
            Str::lower($datos['municipio']) !== Str::lower($datos['ciudad']) ? $datos['municipio'] : '',
            $datos['estado'],
            $esMexico ? '' : $datos['pais'],
        ], fn (string $parte) => $parte !== '');

        return $partes === [] ? null : Str::limit(implode(', ', $partes), 255, '');
    }
}
