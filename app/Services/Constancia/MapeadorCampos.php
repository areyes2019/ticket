<?php

namespace App\Services\Constancia;

use App\Enums\RegimenFiscal;
use Illuminate\Support\Str;

/**
 * Único lugar donde viven las etiquetas de la constancia y del validador del
 * SAT. Si el SAT renombra una etiqueta, el remedio es agregar un alias aquí.
 */
class MapeadorCampos
{
    /**
     * Campo => etiquetas conocidas. Se comparan reducidas a su esqueleto
     * (sin acentos, sin mayúsculas, sin espacios ni signos).
     *
     * @var array<string, list<string>>
     */
    public const ALIAS = [
        'rfc' => ['RFC'],
        'denominacion' => ['Denominación o Razón Social', 'Denominación/Razón Social', 'Denominación', 'Razón Social'],
        'nombre' => ['Nombre', 'Nombre (s)', 'Nombres'],
        'apellido_paterno' => ['Apellido Paterno', 'Primer Apellido'],
        'apellido_materno' => ['Apellido Materno', 'Segundo Apellido'],
        'regimen' => ['Régimen', 'Régimen Fiscal'],
        'codigo_postal' => ['CP', 'Código Postal'],
        'vialidad' => ['Nombre de Vialidad', 'Nombre de la Vialidad', 'Vialidad', 'Calle'],
        'numero_exterior' => ['Número Exterior', 'No Exterior'],
        'numero_interior' => ['Número Interior', 'No Interior'],
        'colonia' => ['Nombre de la Colonia', 'Colonia'],
        'municipio' => ['Nombre del Municipio o Demarcación Territorial', 'Municipio o Delegación', 'Municipio', 'Nombre del Municipio'],
        'entidad' => ['Nombre de la Entidad Federativa', 'Entidad Federativa'],
    ];

    /**
     * Campos del formulario que la constancia debería llenar, con su nombre
     * para los avisos.
     *
     * @var array<string, string>
     */
    private const ESPERADOS = [
        'razon_social' => 'razón social',
        'regimen_fiscal' => 'régimen fiscal',
        'codigo_postal_fiscal' => 'código postal fiscal',
        'direccion_comercial' => 'dirección',
    ];

    /**
     * @var array<string, string>|null
     */
    private ?array $indice = null;

    /**
     * Reduce un texto a su esqueleto: "Nombre de la Colonia" y
     * "NombredelaColonia" son la misma llave.
     */
    public static function esqueleto(string $texto): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii(self::aUtf8($texto))));
    }

    /**
     * El texto de un PDF puede llegar en Windows-1252; sin convertirlo, cada
     * acento es un byte suelto y ningún alias coincide.
     */
    public static function aUtf8(string $texto): string
    {
        return mb_check_encoding($texto, 'UTF-8') ? $texto : mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
    }

    /**
     * Campo al que corresponde una etiqueta, o null si no está en la lista.
     * La longitud mínima es 2 por "CP:"; lo que evita la basura es la lista.
     */
    public function campo(string $etiqueta): ?string
    {
        $llave = self::esqueleto($etiqueta);

        return strlen($llave) >= 2 ? ($this->indice()[$llave] ?? null) : null;
    }

    /**
     * Agrupa los pares etiqueta/valor por campo. Se guardan todas las
     * apariciones: el régimen puede repetirse, los demás usan la primera.
     *
     * @param  list<array{0: string, 1: string}>  $pares
     * @return array<string, list<string>>
     */
    public function agrupar(array $pares): array
    {
        $campos = [];

        foreach ($pares as [$etiqueta, $valor]) {
            $campo = $this->campo($etiqueta);
            $valor = $this->limpiar($valor);

            if ($campo !== null && $valor !== '' && ! in_array($valor, $campos[$campo] ?? [], true)) {
                $campos[$campo][] = $valor;
            }
        }

        return $campos;
    }

    /**
     * Convierte los campos crudos en los datos del formulario.
     *
     * @param  array<string, list<string>>  $campos
     * @return array{data: array<string, mixed>, advertencias: list<string>}
     */
    public function datos(array $campos, string $rfcQr): array
    {
        $data = ['rfc' => $rfcQr];
        $advertencias = [];

        $rfcDocumento = isset($campos['rfc'][0]) ? strtoupper((string) preg_replace('/\s+/', '', $campos['rfc'][0])) : null;

        if ($rfcDocumento !== null && $rfcDocumento !== $rfcQr) {
            $advertencias[] = "El RFC del documento ({$rfcDocumento}) no coincide con el del código QR; se usó el del QR ({$rfcQr}).";
        }

        $razonSocial = $this->razonSocial($campos);

        if ($razonSocial !== null) {
            $data['razon_social'] = $razonSocial;
        }

        $this->regimen($campos['regimen'] ?? [], $data, $advertencias);
        $this->codigoPostal($campos['codigo_postal'][0] ?? null, $data, $advertencias);

        $direccion = $this->direccion($campos);

        if ($direccion !== null) {
            $data['direccion_comercial'] = $direccion;
        }

        return ['data' => $data, 'advertencias' => $advertencias];
    }

    /**
     * Campos esperados que no llegaron, con su nombre para el aviso.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public function faltantes(array $data): array
    {
        return array_values(array_diff_key(self::ESPERADOS, $data));
    }

    /**
     * Clave del régimen a partir de la descripción que publican el SAT y la
     * constancia ("Régimen de las Personas Físicas con Actividades
     * Empresariales y Profesionales"). Si el texto trae la clave, también vale.
     */
    public function resolverRegimen(string $texto): ?RegimenFiscal
    {
        if (preg_match('/\b(6\d\d)\b/', $texto, $clave) && ($regimen = RegimenFiscal::tryFrom($clave[1])) !== null) {
            return $regimen;
        }

        $llave = self::esqueleto($texto);
        $elegido = null;

        foreach (RegimenFiscal::cases() as $regimen) {
            $descripcion = self::esqueleto($regimen->descripcion());

            if (str_contains($llave, $descripcion) && ($elegido === null || strlen($descripcion) > strlen(self::esqueleto($elegido->descripcion())))) {
                $elegido = $regimen;
            }
        }

        return $elegido;
    }

    /**
     * @param  array<string, list<string>>  $campos
     */
    private function razonSocial(array $campos): ?string
    {
        $texto = $campos['denominacion'][0] ?? implode(' ', array_filter([
            $campos['nombre'][0] ?? null,
            $campos['apellido_paterno'][0] ?? null,
            $campos['apellido_materno'][0] ?? null,
        ]));

        return $texto === '' ? null : mb_substr($texto, 0, 255);
    }

    /**
     * @param  list<string>  $textos
     * @param  array<string, mixed>  $data
     * @param  list<string>  $advertencias
     */
    private function regimen(array $textos, array &$data, array &$advertencias): void
    {
        $regimenes = [];

        foreach ($textos as $texto) {
            $regimen = $this->resolverRegimen($texto);

            if ($regimen === null) {
                $advertencias[] = "No se reconoció el régimen fiscal «{$texto}»; selecciónalo en la lista.";
            } else {
                $regimenes[$regimen->value] = $regimen;
            }
        }

        if ($regimenes === []) {
            return;
        }

        // Las claves numéricas del arreglo se vuelven int; se toma el valor del enum.
        $data['regimen_fiscal'] = reset($regimenes)->value;
        $data['regimenes_disponibles'] = array_values(array_map(fn (RegimenFiscal $regimen) => [
            'clave' => $regimen->value,
            'texto' => $regimen->value.' – '.$regimen->descripcion(),
        ], $regimenes));

        if (count($regimenes) > 1) {
            $claves = implode(', ', array_keys($regimenes));
            $advertencias[] = "El contribuyente tiene varios regímenes ({$claves}); se propuso el {$data['regimen_fiscal']}. Confirma cuál corresponde a este cliente.";
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $advertencias
     */
    private function codigoPostal(?string $texto, array &$data, array &$advertencias): void
    {
        if ($texto === null) {
            return;
        }

        $codigo = (string) preg_replace('/\s+/', '', $texto);

        if (preg_match('/^\d{5}$/', $codigo)) {
            $data['codigo_postal_fiscal'] = $codigo;
        } else {
            $advertencias[] = "El código postal «{$texto}» no tiene 5 dígitos; captúralo a mano.";
        }
    }

    /**
     * Domicilio en una sola línea para direccion_comercial:
     * "JAGUARES 5208, COL CIUDAD OLMECA, COATZACOALCOS, VERACRUZ DE IGNACIO DE LA LLAVE".
     *
     * @param  array<string, list<string>>  $campos
     */
    private function direccion(array $campos): ?string
    {
        $primero = fn (string $campo): ?string => $campos[$campo][0] ?? null;

        $interior = $primero('numero_interior');
        $calle = implode(' ', array_filter([
            $primero('vialidad'),
            $primero('numero_exterior'),
            $interior !== null ? 'INT '.$interior : null,
        ]));

        $colonia = $primero('colonia');

        $partes = array_filter([
            $calle,
            $colonia !== null ? 'COL '.$colonia : null,
            $primero('municipio'),
            $primero('entidad'),
        ], fn (?string $parte) => $parte !== null && $parte !== '');

        return $partes === [] ? null : mb_substr(implode(', ', $partes), 0, 255);
    }

    private function limpiar(string $valor): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(self::aUtf8($valor), ENT_QUOTES | ENT_HTML5, 'UTF-8')), " \t\n\r\0\x0B,");
    }

    /**
     * @return array<string, string>
     */
    private function indice(): array
    {
        if ($this->indice === null) {
            $this->indice = [];

            foreach (self::ALIAS as $campo => $etiquetas) {
                foreach ($etiquetas as $etiqueta) {
                    $this->indice[self::esqueleto($etiqueta)] = $campo;
                }
            }
        }

        return $this->indice;
    }
}
