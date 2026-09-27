<?php

use App\Services\Documentos\CalculadoraTotalesDocumento;

/*
 * El fixture es la definición ejecutable del algoritmo de totales. El mismo
 * archivo lo recorre tests/js/totales-documento.test.js contra el espejo en
 * JavaScript: cambiar el algoritmo de un lado sin el otro rompe la suite.
 */
dataset('totales', function () {
    $casos = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/totales-documentos.json'), true);

    return collect($casos)->mapWithKeys(fn (array $caso) => [$caso['caso'] => [$caso]])->all();
});

it('calcula los totales del fixture compartido', function (array $caso) {
    expect(CalculadoraTotalesDocumento::calcular($caso['lineas'], $caso['descuento_global_tipo'], $caso['descuento_global_valor']))
        ->toBe($caso['esperado']);
})->with('totales');

it('reparte el prorrateo sin perder ni sobrar centavos', function () {
    $partes = CalculadoraTotalesDocumento::prorratear(1000, [3333, 3333, 3333]);

    expect($partes)->toBe([334, 333, 333])
        ->and(array_sum($partes))->toBe(1000);
});

it('convierte entre pesos y centavos', function () {
    expect(CalculadoraTotalesDocumento::centavos('10.05'))->toBe(1005)
        ->and(CalculadoraTotalesDocumento::centavos(0.1 + 0.2))->toBe(30)
        ->and(CalculadoraTotalesDocumento::pesos(1005))->toBe('10.05')
        ->and(CalculadoraTotalesDocumento::pesos(-7))->toBe('-0.07');
});
