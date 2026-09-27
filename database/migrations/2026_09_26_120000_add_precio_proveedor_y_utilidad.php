<?php

use App\Services\Articulos\CalculadoraPrecioArticulo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Separa costo de precio de venta: el precio actual de cada artículo pasa
     * a ser su precio de lista del proveedor, hereda la utilidad del catálogo
     * (0% en todos) y su cadena se recalcula con la misma calculadora que usa
     * la aplicación. Es determinista: se puede repetir sin perder nada.
     */
    public function up(): void
    {
        Schema::table('catalogos', function (Blueprint $table) {
            $table->decimal('utilidad_porcentaje', 5, 2)->default(0)->after('descuento');
        });

        Schema::table('articulos', function (Blueprint $table) {
            $table->decimal('precio_proveedor', 10, 2)->nullable()->after('objeto_imp');
            $table->decimal('utilidad_porcentaje', 5, 2)->nullable()->after('precio_proveedor');
            $table->renameColumn('precio_con_descuento', 'costo_con_descuento');
        });

        // Incluye los artículos eliminados, para que ninguno quede sin precio de lista.
        DB::transaction(function () {
            $catalogos = DB::table('catalogos')->get(['id', 'descuento', 'utilidad_porcentaje'])->keyBy('id');

            DB::table('articulos')->orderBy('id')->chunkById(500, function ($articulos) use ($catalogos) {
                foreach ($articulos as $articulo) {
                    $catalogo = $catalogos[$articulo->catalogo_id];
                    $costo = CalculadoraPrecioArticulo::costoConDescuento($articulo->precio_unitario_sin_iva, $catalogo->descuento);

                    DB::table('articulos')->where('id', $articulo->id)->update([
                        'precio_proveedor' => $articulo->precio_unitario_sin_iva,
                        'utilidad_porcentaje' => null,
                        'costo_con_descuento' => $costo,
                        'precio_unitario_sin_iva' => CalculadoraPrecioArticulo::precioVentaSinIva($costo, $catalogo->utilidad_porcentaje),
                    ]);
                }
            });
        });

        Schema::table('articulos', function (Blueprint $table) {
            $table->decimal('precio_proveedor', 10, 2)->nullable(false)->change();
        });
    }

    /**
     * El precio de lista vuelve a ser el precio unitario, y el precio con
     * descuento se recalcula como en 008.
     */
    public function down(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->renameColumn('costo_con_descuento', 'precio_con_descuento');
        });

        DB::transaction(function () {
            $descuentos = DB::table('catalogos')->pluck('descuento', 'id');

            DB::table('articulos')->orderBy('id')->chunkById(500, function ($articulos) use ($descuentos) {
                foreach ($articulos as $articulo) {
                    DB::table('articulos')->where('id', $articulo->id)->update([
                        'precio_unitario_sin_iva' => $articulo->precio_proveedor,
                        'precio_con_descuento' => CalculadoraPrecioArticulo::costoConDescuento($articulo->precio_proveedor, $descuentos[$articulo->catalogo_id]),
                    ]);
                }
            });
        });

        Schema::table('articulos', function (Blueprint $table) {
            $table->dropColumn(['precio_proveedor', 'utilidad_porcentaje']);
        });

        Schema::table('catalogos', function (Blueprint $table) {
            $table->dropColumn('utilidad_porcentaje');
        });
    }
};
