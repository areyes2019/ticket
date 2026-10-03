<?php

namespace App\Services\Articulos;

use App\Enums\ObjetoImpuesto;
use App\Models\Articulo;

/**
 * Cadena de precios de un artículo: precio de lista del proveedor → costo con
 * el descuento del catálogo → precio de venta con el markup de utilidad →
 * ajuste para que el precio que lee el cliente sea un peso entero.
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

    /**
     * Precio de venta sin IVA ya ajustado al peso entero: markup y después el
     * redondeo con el factor de IVA del artículo.
     */
    public static function precioVentaFinal(float|string $costo, float|string $utilidadPorcentaje, ?ObjetoImpuesto $objetoImp): float
    {
        return self::redondearAPesoEntero(self::precioVentaSinIva($costo, $utilidadPorcentaje), self::factorIva($objetoImp));
    }

    /**
     * IVA que se suma encima del precio sin IVA para llegar al que lee el
     * cliente: solo en artículos que sí son objeto de impuesto. Es propiedad
     * del artículo; la tasa de cada renglón de un documento es otra cosa.
     */
    public static function tasaIva(?ObjetoImpuesto $objetoImp): float
    {
        return $objetoImp === ObjetoImpuesto::SiObjeto ? Articulo::TASA_IVA : 0.0;
    }

    public static function factorIva(?ObjetoImpuesto $objetoImp): float
    {
        return 1 + self::tasaIva($objetoImp);
    }

    /**
     * El precio sin IVA, de dos decimales y nunca menor al crudo, cuyo precio
     * con IVA (redondeado a centavos) es un peso entero. Con 1.16 no todo
     * peso es alcanzable ($7, $12, $17…): un centavo sin IVA son 1.16 con
     * IVA, más que la ventana de un centavo. Nunca hay dos inalcanzables
     * seguidos, así que el ciclo da a lo más una vuelta extra.
     */
    public static function redondearAPesoEntero(float|string $precioCrudoSinIva, float $factorIva): float
    {
        $crudo = (float) $precioCrudoSinIva;

        if ($crudo <= 0) {
            return 0.0;
        }

        $objetivo = ceil(round($crudo * $factorIva, 6));

        while (true) {
            $centavos = floor(round($objetivo * 100 / $factorIva, 6));

            foreach ([$centavos, $centavos + 1] as $candidato) {
                if (self::redondeo2($candidato / 100 * $factorIva) === $objetivo) {
                    return $candidato / 100;
                }
            }

            $objetivo++;
        }
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
