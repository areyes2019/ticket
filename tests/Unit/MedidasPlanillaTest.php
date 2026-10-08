<?php

use App\Services\Etiquetas\MedidasPlanilla;

/*
 * El fixture es la definición ejecutable de la distribución de la planilla.
 * El mismo archivo lo recorre tests/js/etiquetas-produccion.test.js contra el
 * espejo en JavaScript.
 */
dataset('planillas', function () {
    $casos = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/planillas-etiquetas.json'), true);

    return collect($casos)->mapWithKeys(fn (array $caso) => [$caso['descripcion'] => [$caso]])->all();
});

it('reparte la hoja como dice el fixture compartido', function (array $caso) {
    $medidas = MedidasPlanilla::desdeMilimetros([...$caso['medidas'], 'columnas' => $caso['columnas_elegidas'] ?? null]);
    $centrada = $medidas->centrada();

    expect($medidas->columnas())->toBe($caso['columnas'])
        ->and($medidas->columnasQueCaben())->toBe($caso['columnas_que_caben'] ?? $caso['columnas'])
        ->and($medidas->columnasRecortadas())->toBe($caso['columnas_recortadas'] ?? false)
        ->and($centrada->columnasElegidas())->toBe($caso['columnas_elegidas'] ?? null)
        ->and($medidas->renglones())->toBe($caso['renglones'])
        ->and($medidas->porHoja())->toBe($caso['por_hoja'])
        ->and($medidas->cabe())->toBe($caso['por_hoja'] > 0)
        ->and(number_format($medidas->escalaLetra(), 4, '.', ''))->toBe($caso['escala_letra'])
        ->and($centrada->milimetros('margen_superior'))->toBe($caso['centrado']['margen_superior'])
        ->and($centrada->milimetros('margen_izquierdo'))->toBe($caso['centrado']['margen_izquierdo'])
        ->and($centrada->milimetros('ancho'))->toBe($caso['medidas']['ancho']);
})->with('planillas');

it('de fábrica es la planilla de 030', function () {
    expect(MedidasPlanilla::fabrica()->toArray())->toBe([
        'ancho' => '60.0',
        'alto' => '30.0',
        'separacion_horizontal' => '0.0',
        'separacion_vertical' => '0.0',
        'margen_superior' => '19.7',
        'margen_izquierdo' => '17.9',
    ]);
});

it('toma de la petición solo las medidas válidas', function () {
    $medidas = MedidasPlanilla::desdePeticion([
        'ancho' => '70.25',
        'alto' => 'abc',
        'separacion_horizontal' => '60',
        'separacion_vertical' => ['2'],
        'margen_superior' => '5',
    ], MedidasPlanilla::fabrica());

    expect($medidas->toArray())->toBe([
        'ancho' => '70.3',
        'alto' => '30.0',
        'separacion_horizontal' => '0.0',
        'separacion_vertical' => '0.0',
        'margen_superior' => '5.0',
        'margen_izquierdo' => '17.9',
    ]);
});

it('de fábrica pone las columnas en automático', function () {
    expect(MedidasPlanilla::fabrica()->columnasElegidas())->toBeNull()
        ->and(MedidasPlanilla::fabrica()->columnasParaDireccion())->toBe('auto');
});

it('toma de la petición solo columnas válidas', function (mixed $valor, ?int $esperadas) {
    $base = MedidasPlanilla::desdeMilimetros([...MedidasPlanilla::fabrica()->toArray(), 'columnas' => 2]);

    expect(MedidasPlanilla::desdePeticion(['columnas' => $valor], $base)->columnasElegidas())->toBe($esperadas);
})->with([
    'una' => ['1', 1],
    'tres' => [3, 3],
    'automático' => ['auto', null],
    'vacío es automático' => ['', null],
    'cinco se ignora' => ['5', 2],
    'texto se ignora' => ['x', 2],
    'arreglo se ignora' => [['2'], 2],
]);

it('sin columnas en la petición conserva las de la base', function () {
    $base = MedidasPlanilla::desdeMilimetros([...MedidasPlanilla::fabrica()->toArray(), 'columnas' => 2]);

    expect(MedidasPlanilla::desdePeticion(['ancho' => '50'], $base)->columnasElegidas())->toBe(2);
});
