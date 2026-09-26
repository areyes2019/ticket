<?php

use App\Services\Constancia\MapeadorCampos;
use App\Services\Constancia\ParesPorPosicion;

/**
 * Coloca un texto palabra por palabra, como lo dibuja la constancia real.
 *
 * @return list<array{x: float, y: float, texto: string}>
 */
function trozosDeCelda(float $x, float $y, string $texto): array
{
    $trozos = [];

    foreach (explode(' ', $texto) as $palabra) {
        $trozos[] = ['x' => $x, 'y' => $y, 'texto' => $palabra];
        $x += mb_strlen($palabra) * 4.4 + 2.5;
    }

    return $trozos;
}

it('recupera los espacios que el PDF no guarda dentro de un valor', function () {
    $pares = (new ParesPorPosicion)->pares(trozosDeCelda(40, 600, 'Nombre de la Colonia: CIUDAD OLMECA'));

    expect($pares)->toBe([['Nombre de la Colonia', 'CIUDAD OLMECA']]);
});

it('separa las dos columnas del domicilio sin arrastrar la etiqueta vecina', function () {
    $pares = (new ParesPorPosicion)->pares([
        ...trozosDeCelda(40, 600, 'Código Postal: 96535'),
        ...trozosDeCelda(330, 600, 'Tipo de Vialidad: CALLE'),
    ]);

    expect($pares)->toBe([['Código Postal', '96535'], ['Tipo de Vialidad', 'CALLE']]);
});

it('no deja que una etiqueta sin valor se quede con el renglón siguiente', function () {
    $pares = (new ParesPorPosicion)->pares([
        ...trozosDeCelda(40, 600, 'Número Interior:'),
        ...trozosDeCelda(330, 600, 'Nombre de la Colonia: INDUSTRIAL'),
        ...trozosDeCelda(40, 588, 'Nombre de la Localidad: CELAYA'),
    ]);

    expect($pares)->toBe([
        ['Número Interior', ''],
        ['Nombre de la Colonia', 'INDUSTRIAL'],
        ['Nombre de la Localidad', 'CELAYA'],
    ]);
});

it('une un valor partido en dos renglones', function () {
    $pares = (new ParesPorPosicion)->pares([
        ...trozosDeCelda(40, 560, 'Nombre de la Entidad Federativa: VERACRUZ DE IGNACIO DE LA'),
        ...trozosDeCelda(400, 560, 'Entre Calle: PUMAS'),
        ...trozosDeCelda(185, 548, 'LLAVE'),
    ]);

    expect($pares)->toBe([
        ['Nombre de la Entidad Federativa', 'VERACRUZ DE IGNACIO DE LA LLAVE'],
        ['Entre Calle', 'PUMAS'],
    ]);
});

it('no toma un encabezado en minúsculas como continuación', function () {
    $pares = (new ParesPorPosicion)->pares([
        ...trozosDeCelda(40, 600, 'Primer Apellido: GOMEZ'),
        ...trozosDeCelda(40, 588, 'Datos del domicilio registrado'),
    ]);

    expect($pares)->toBe([['Primer Apellido', 'GOMEZ']]);
});

it('recoge las filas de la tabla de regímenes, que no llevan etiqueta', function () {
    $pares = (new ParesPorPosicion)->pares([
        ...trozosDeCelda(40, 500, 'Régimen'),
        ...trozosDeCelda(420, 500, 'Fecha Inicio'),
        ...trozosDeCelda(40, 488, 'Régimen de Sueldos y Salarios e Ingresos Asimilados a Salarios'),
        ...trozosDeCelda(420, 488, '01/01/2015'),
    ]);

    expect($pares)->toBe([['Régimen', 'Régimen de Sueldos y Salarios e Ingresos Asimilados a Salarios']]);
});

describe('MapeadorCampos', function () {
    it('resuelve el régimen por su descripción', function (string $texto, string $clave) {
        expect((new MapeadorCampos)->resolverRegimen($texto)?->value)->toBe($clave);
    })->with([
        ['Régimen de las Personas Físicas con Actividades Empresariales y Profesionales', '612'],
        ['Régimen de Sueldos y Salarios e Ingresos Asimilados a Salarios', '605'],
        ['Régimen General de Ley Personas Morales', '601'],
        ['Régimen de las Personas Morales con Fines no Lucrativos', '603'],
        ['Régimen Simplificado de Confianza', '626'],
        ['626 - Régimen Simplificado de Confianza', '626'],
    ]);

    it('reconoce etiquetas con y sin espacios, acentos o barra', function (string $etiqueta, string $campo) {
        expect((new MapeadorCampos)->campo($etiqueta))->toBe($campo);
    })->with([
        ['NombredelaColonia', 'colonia'],
        ['Nombre de la Colonia', 'colonia'],
        ['CP', 'codigo_postal'],
        ['Denominación/Razón Social', 'denominacion'],
        ['Denominacion o Razon Social', 'denominacion'],
    ]);

    it('ignora etiquetas que no están en la lista', function () {
        expect((new MapeadorCampos)->campo('El RFC'))->toBeNull()
            ->and((new MapeadorCampos)->campo('Régimen Capital'))->toBeNull();
    });

    it('convierte el texto de Windows-1252 antes de comparar', function () {
        $etiqueta = mb_convert_encoding('Denominación o Razón Social', 'Windows-1252', 'UTF-8');

        expect((new MapeadorCampos)->campo($etiqueta))->toBe('denominacion');
    });

    it('deja vacío un código postal que no tiene 5 dígitos y avisa', function () {
        $resultado = (new MapeadorCampos)->datos(['codigo_postal' => ['9653']], 'GOPM800101AB1');

        expect($resultado['data'])->not->toHaveKey('codigo_postal_fiscal')
            ->and($resultado['advertencias'])->toBe(['El código postal «9653» no tiene 5 dígitos; captúralo a mano.']);
    });

    it('arma el domicilio en una línea sin comas sueltas', function () {
        $resultado = (new MapeadorCampos)->datos([
            'vialidad' => ['AV TECNOLOGICO'],
            'numero_exterior' => ['105'],
            'entidad' => ['GUANAJUATO'],
        ], 'PME120315AB9');

        expect($resultado['data']['direccion_comercial'])->toBe('AV TECNOLOGICO 105, GUANAJUATO');
    });
});
