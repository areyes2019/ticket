<?php

namespace App\Services\Articulos;

/**
 * Cadena de precios de un artículo: precio de lista del proveedor → costo con
 * el descuento del catálogo → precio de venta con el markup de utilidad.
 *
 * Único lugar de PHP que conoce la fórmula. Su espejo en el navegador es
 * public/js/precio-articulo.js; los dos recorren tests/Fixtures/precios-articulos.json.
 */
final class CalculadoraPrecioArticulo
{
    /**
     * Redondeo a centavos, mitad hacia arriba. El redondeo a 6 decimales
     * sobre el valor ya expresado en centavos quita el ruido de punto flotante
     * (10.05 × 0.9 × 100 = 904.4999…) sin reintroducirlo al escalar.
     */
    public static function redondeo2(float $valor): float
    {
        return round(round($valor * 100, 6)) / 100;
    }

    /**
     * Techo a centavos, para que el precio de venta nunca quede por debajo del
     * markup pedido. Mismo orden que redondeo2: primero se escala y luego se
     * quita el ruido (15.40 × 1.05 debe dar 16.17, no 16.18).
     */
    public static function techo2(float $valor): float
    {
        return ceil(round($valor * 100, 6)) / 100;
    }

    public static function costoConDescuento(float|string $precioProveedor, float|string $descuento): float
    {
        return self::redondeo2((float) $precioProveedor * (1 - (float) $descuento / 100));
    }

    /**
     * Markup sobre el costo: 25% significa ganar el 25% de lo que costó.
     */
    public static function precioVentaSinIva(float|string $costo, float|string $utilidadPorcentaje): float
    {
        return self::techo2((float) $costo * (1 + (float) $utilidadPorcentaje / 100));
    }

    public static function utilidad(float|string $precioVentaSinIva, float|string $costo): float
    {
        return self::redondeo2((float) $precioVentaSinIva - (float) $costo);
    }

    public static function precioConIva(float|string $precioSinIva, float $tasaIva): float
    {
        return self::redondeo2((float) $precioSinIva * (1 + $tasaIva));
    }
}
