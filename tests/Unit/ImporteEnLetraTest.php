<?php

use App\Services\Facturacion\ImporteEnLetra;

it('escribe el importe con letra como en un CFDI', function (string $importe, string $esperado) {
    expect(ImporteEnLetra::convertir($importe))->toBe($esperado);
})->with([
    ['0.50', 'CERO PESOS 50/100 M.N.'],
    ['1.00', 'UN PESO 00/100 M.N.'],
    ['21.00', 'VEINTIÚN PESOS 00/100 M.N.'],
    ['100.00', 'CIEN PESOS 00/100 M.N.'],
    ['116.00', 'CIENTO DIECISÉIS PESOS 00/100 M.N.'],
    ['580.00', 'QUINIENTOS OCHENTA PESOS 00/100 M.N.'],
    ['1160.50', 'MIL CIENTO SESENTA PESOS 50/100 M.N.'],
    ['21345.07', 'VEINTIÚN MIL TRESCIENTOS CUARENTA Y CINCO PESOS 07/100 M.N.'],
    ['1000000.00', 'UN MILLÓN DE PESOS 00/100 M.N.'],
    ['2500001.99', 'DOS MILLONES QUINIENTOS MIL UN PESOS 99/100 M.N.'],
]);
