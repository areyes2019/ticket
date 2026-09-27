<?php

use App\Services\Articulos\CalculadoraPrecioArticulo;

/*
 * El fixture es la definición ejecutable de la cadena de precios. El mismo
 * archivo lo recorre tests/js/precio-articulo.test.js contra el espejo en
 * JavaScript: cambiar una fórmula sin la otra rompe la suite del lado no tocado.
 */
dataset('precios', function () {
    $casos = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/precios-articulos.json'), true);

    return collect($casos)->mapWithKeys(fn (array $caso) => [$caso['descripcion'] => [$caso]])->all();
});

it('calcula la cadena del fixture compartido', function (array $caso) {
    $costo = CalculadoraPrecioArticulo::costoConDescuento($caso['precio_proveedor'], $caso['descuento']);
    $venta = CalculadoraPrecioArticulo::precioVentaSinIva($costo, $caso['utilidad_porcentaje']);

    expect(number_format($costo, 2, '.', ''))->toBe($caso['costo'])
        ->and(number_format($venta, 2, '.', ''))->toBe($caso['precio_venta'])
        ->and(number_format(CalculadoraPrecioArticulo::utilidad($venta, $costo), 2, '.', ''))->toBe($caso['utilidad']);
})->with('precios');

it('calcula el precio con IVA a centavos', function () {
    expect(CalculadoraPrecioArticulo::precioConIva(100, 0.16))->toBe(116.0)
        ->and(CalculadoraPrecioArticulo::precioConIva(225, 0.16))->toBe(261.0);
});
