<?php

use App\Enums\ObjetoImpuesto;
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
    $objeto = ObjetoImpuesto::from($caso['objeto_imp']);
    $costo = CalculadoraPrecioArticulo::costoConDescuento($caso['precio_proveedor'], $caso['descuento']);
    $crudo = CalculadoraPrecioArticulo::precioVentaSinIva($costo, $caso['utilidad_porcentaje']);
    $venta = CalculadoraPrecioArticulo::precioVentaFinal($costo, $caso['utilidad_porcentaje'], $objeto);
    $conIva = CalculadoraPrecioArticulo::precioConIva($venta, CalculadoraPrecioArticulo::tasaIva($objeto));

    expect(number_format($costo, 2, '.', ''))->toBe($caso['costo'])
        ->and(number_format($crudo, 2, '.', ''))->toBe($caso['precio_venta_crudo'])
        ->and(number_format($venta, 2, '.', ''))->toBe($caso['precio_venta'])
        ->and(number_format($conIva, 2, '.', ''))->toBe($caso['precio_con_iva'])
        ->and(number_format(CalculadoraPrecioArticulo::utilidad($venta, $costo), 2, '.', ''))->toBe($caso['utilidad']);

    // 028: el precio distribuidor parte del mismo costo con su propia utilidad.
    if (isset($caso['utilidad_distribuidor_porcentaje'])) {
        $distribuidor = CalculadoraPrecioArticulo::precioVentaFinal($costo, $caso['utilidad_distribuidor_porcentaje'], $objeto);

        expect(number_format($distribuidor, 2, '.', ''))->toBe($caso['precio_distribuidor'])
            ->and(number_format(CalculadoraPrecioArticulo::precioConIva($distribuidor, CalculadoraPrecioArticulo::tasaIva($objeto)), 2, '.', ''))->toBe($caso['precio_distribuidor_con_iva']);
    }
})->with('precios');

it('calcula el precio con IVA a centavos', function () {
    expect(CalculadoraPrecioArticulo::precioConIva(100, 0.16))->toBe(116.0)
        ->and(CalculadoraPrecioArticulo::precioConIva(225, 0.16))->toBe(261.0);
});

it('aplica IVA solo a lo que es objeto de impuesto', function (?ObjetoImpuesto $objeto, float $factor) {
    expect(CalculadoraPrecioArticulo::factorIva($objeto))->toBe($factor);
})->with([
    '01' => [ObjetoImpuesto::NoObjeto, 1.0],
    '02' => [ObjetoImpuesto::SiObjeto, 1.16],
    '03' => [ObjetoImpuesto::SiObjetoNoObligadoDesglose, 1.0],
    '04' => [ObjetoImpuesto::SiObjetoNoCausa, 1.0],
    'sin objeto' => [null, 1.0],
]);

/*
 * Barrido de todos los precios de $0.01 a $2,000.00. Es lo que encuentra los
 * pesos inalcanzables con 1.16 ($7, $12, $17…); casos sueltos no los verían.
 */
it('deja el precio en un peso entero sin bajarlo ni subirlo $2 o más', function (float $factor) {
    $fallos = [];

    for ($centavos = 1; $centavos <= 200000; $centavos++) {
        $crudo = $centavos / 100;
        $final = CalculadoraPrecioArticulo::redondearAPesoEntero($crudo, $factor);
        $conIva = CalculadoraPrecioArticulo::redondeo2($final * $factor);
        $ajuste = $conIva - CalculadoraPrecioArticulo::redondeo2($crudo * $factor);

        if ($conIva !== floor($conIva) || round($final * 100) < $centavos || $ajuste >= 2) {
            $fallos[] = $crudo;
        }
    }

    expect($fallos)->toBe([]);
})->with(['con IVA' => 1.16, 'sin IVA' => 1.0]);
