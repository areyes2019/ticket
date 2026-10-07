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
    $medidas = MedidasPlanilla::desdeMilimetros($caso['medidas']);
    $centrada = $medidas->centrada();

    expect($medidas->columnas())->toBe($caso['columnas'])
        ->and($medidas->renglones())->toBe($caso['renglones'])
        ->and($medidas->porHoja())->toBe($caso['por_hoja'])
        ->and($medidas->cabe())->toBe($caso['por_hoja'] > 0)
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
