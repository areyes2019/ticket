<?php

namespace App\Services\Documentos;

use App\Enums\TasaIva;
use App\Enums\TipoDescuento;

/**
 * Totales de un documento con líneas (cotización hoy, factura después), en
 * dos pasadas: primero el descuento de cada línea, después el descuento
 * global prorrateado entre las líneas antes del IVA.
 *
 * Trabaja en centavos enteros para que las sumas sean exactas. Único lugar de
 * PHP que conoce el algoritmo; su espejo en el navegador es
 * public/js/totales-documento.js y los dos recorren
 * tests/Fixtures/totales-documentos.json.
 */
final class CalculadoraTotalesDocumento
{
    /**
     * @param  list<array{cantidad: int|string, precio_unitario: float|string, descuento_tipo?: string|null, descuento_valor?: float|string|null, tasa_iva: string}>  $lineas
     * @return array{lineas: list<array{bruto: string, descuento: string, importe: string, iva_importe: string}>, subtotal: string, total_descuento: string, base_iva_16: string, total_iva_16: string, base_iva_0: string, base_exento: string, total: string}
     */
    public static function calcular(array $lineas, ?string $descuentoGlobalTipo = null, float|string|null $descuentoGlobalValor = null): array
    {
        // Primera pasada: bruto y descuento de cada línea.
        $calculadas = [];

        foreach ($lineas as $linea) {
            $bruto = (int) $linea['cantidad'] * self::centavos($linea['precio_unitario']);
            $descuento = self::descuento($bruto, $linea['descuento_tipo'] ?? null, $linea['descuento_valor'] ?? null);

            $calculadas[] = [
                'bruto' => $bruto,
                'descuento' => $descuento,
                'neto' => $bruto - $descuento,
                'tasa' => TasaIva::from((string) $linea['tasa_iva']),
            ];
        }

        // Segunda pasada: descuento global prorrateado.
        $netos = array_column($calculadas, 'neto');
        $descuentoGlobal = self::descuento(array_sum($netos), $descuentoGlobalTipo, $descuentoGlobalValor);
        $partes = self::prorratear($descuentoGlobal, $netos);

        $totales = ['subtotal' => 0, 'total_descuento' => $descuentoGlobal, 'base_iva_16' => 0, 'total_iva_16' => 0, 'base_iva_0' => 0, 'base_exento' => 0];
        $resultado = [];

        foreach ($calculadas as $i => $linea) {
            $importe = $linea['neto'] - $partes[$i];
            $iva = self::redondear($importe * $linea['tasa']->factor());

            $totales['subtotal'] += $linea['bruto'];
            $totales['total_descuento'] += $linea['descuento'];
            $totales['total_iva_16'] += $iva;
            $totales[match ($linea['tasa']) {
                TasaIva::Dieciseis => 'base_iva_16',
                TasaIva::Cero => 'base_iva_0',
                TasaIva::Exento => 'base_exento',
            }] += $importe;

            $resultado[] = [
                'bruto' => self::pesos($linea['bruto']),
                'descuento' => self::pesos($linea['descuento']),
                'importe' => self::pesos($importe),
                'iva_importe' => self::pesos($iva),
            ];
        }

        $total = $totales['subtotal'] - $totales['total_descuento'] + $totales['total_iva_16'];

        return [
            'lineas' => $resultado,
            ...array_map(self::pesos(...), $totales),
            'total' => self::pesos($total),
        ];
    }

    /**
     * Descuento en centavos sobre una base en centavos. Un monto mayor que la
     * base se limita a la base (el Form Request ya lo rechaza).
     */
    public static function descuento(int $base, ?string $tipo, float|string|null $valor): int
    {
        if ($tipo === null || $tipo === '' || $valor === null || $valor === '') {
            return 0;
        }

        $descuento = TipoDescuento::from($tipo) === TipoDescuento::Porcentaje
            ? self::redondear($base * (float) $valor / 100)
            : self::centavos($valor);

        return max(0, min($descuento, $base));
    }

    /**
     * Precio unitario con el descuento de la línea ya adentro (023): el neto
     * de la línea (antes del descuento global) entre la cantidad, redondeado
     * a centavos. Sin descuento es el mismo precio. El residuo de centavos
     * que deja la división no se compensa.
     */
    public static function precioConDescuentoDeLinea(int|string $cantidad, float|string $precioUnitario, ?string $descuentoTipo, float|string|null $descuentoValor): string
    {
        $cantidad = (int) $cantidad;

        if ($cantidad <= 0) {
            return self::pesos(self::centavos($precioUnitario));
        }

        $bruto = $cantidad * self::centavos($precioUnitario);

        return self::pesos(self::redondear(($bruto - self::descuento($bruto, $descuentoTipo, $descuentoValor)) / $cantidad));
    }

    /**
     * Reparte un monto en proporción a cada neto. Los centavos que deja el
     * redondeo van a la línea de mayor neto (la primera si hay empate), para
     * que la suma de las partes sea exactamente el monto.
     *
     * @param  list<int>  $netos
     * @return list<int>
     */
    public static function prorratear(int $monto, array $netos): array
    {
        $suma = array_sum($netos);

        if ($monto === 0 || $suma === 0) {
            return array_fill(0, count($netos), 0);
        }

        $partes = array_map(fn (int $neto) => self::redondear($monto * $neto / $suma), $netos);
        $mayor = array_search(max($netos), $netos, true);
        $partes[$mayor] += $monto - array_sum($partes);

        return $partes;
    }

    /**
     * Pesos (número o texto con hasta 2 decimales) a centavos enteros.
     */
    public static function centavos(float|string|null $pesos): int
    {
        return self::redondear((float) $pesos * 100);
    }

    /**
     * Centavos a texto decimal con 2 decimales ("1148.40"), como lo guarda MySQL.
     */
    public static function pesos(int $centavos): string
    {
        $signo = $centavos < 0 ? '-' : '';
        $centavos = abs($centavos);

        return $signo.intdiv($centavos, 100).'.'.str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Redondeo al entero, mitad hacia arriba. El redondeo previo a 6 decimales
     * quita el ruido de punto flotante (mismo criterio que CalculadoraPrecioArticulo).
     */
    private static function redondear(float $valor): int
    {
        return (int) round(round($valor, 6));
    }
}
