<?php

use App\Enums\ObjetoImpuesto;
use App\Services\Articulos\CalculadoraPrecioArticulo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Recalcula el precio de venta de todos los artículos (incluidos los
     * eliminados) con el redondeo al peso entero. Solo datos: el costo no
     * cambia y las entradas capturadas no se tocan, así que es determinista
     * y se puede repetir sin mover nada. Los precios suben de $0.00 a $1.99.
     */
    public function up(): void
    {
        $this->recalcular(fn (float $costo, string $utilidad, ?string $objetoImp) => CalculadoraPrecioArticulo::precioVentaFinal($costo, $utilidad, ObjetoImpuesto::tryFrom((string) $objetoImp)));
    }

    /**
     * Vuelve al precio de venta que da el markup, sin redondeo.
     */
    public function down(): void
    {
        $this->recalcular(fn (float $costo, string $utilidad) => CalculadoraPrecioArticulo::precioVentaSinIva($costo, $utilidad));
    }

    /**
     * @param  Closure(float, string, ?string): float  $precioVenta
     */
    private function recalcular(Closure $precioVenta): void
    {
        DB::transaction(function () use ($precioVenta) {
            $utilidades = DB::table('catalogos')->pluck('utilidad_porcentaje', 'id');

            DB::table('articulos')->orderBy('id')->chunkById(500, function ($articulos) use ($utilidades, $precioVenta) {
                foreach ($articulos as $articulo) {
                    $utilidad = (string) ($articulo->utilidad_porcentaje ?? $utilidades[$articulo->catalogo_id]);

                    DB::table('articulos')->where('id', $articulo->id)->update([
                        'precio_unitario_sin_iva' => $precioVenta((float) $articulo->costo_con_descuento, $utilidad, $articulo->objeto_imp),
                    ]);
                }
            });
        });
    }
};
