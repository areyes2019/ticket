<?php

use App\Enums\ObjetoImpuesto;
use App\Services\Articulos\CalculadoraPrecioArticulo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 028: la utilidad distribuidor del catálogo (0% en los existentes), la
     * propia del artículo (null = hereda) y el precio distribuidor que el
     * modelo calcula y guarda, rellenado aquí para todos los artículos
     * (incluidos los eliminados). Con 0% el precio distribuidor arranca en el
     * costo, ajustado al peso entero.
     */
    public function up(): void
    {
        Schema::table('catalogos', function (Blueprint $table) {
            $table->decimal('utilidad_distribuidor_porcentaje', 5, 2)->default(0)->after('utilidad_porcentaje');
        });

        Schema::table('articulos', function (Blueprint $table) {
            $table->decimal('utilidad_distribuidor_porcentaje', 5, 2)->nullable()->after('utilidad_porcentaje');
            $table->decimal('precio_distribuidor_sin_iva', 10, 2)->default(0)->after('precio_unitario_sin_iva');
        });

        DB::transaction(function () {
            $utilidades = DB::table('catalogos')->pluck('utilidad_distribuidor_porcentaje', 'id');

            DB::table('articulos')->orderBy('id')->chunkById(500, function ($articulos) use ($utilidades) {
                foreach ($articulos as $articulo) {
                    DB::table('articulos')->where('id', $articulo->id)->update([
                        'precio_distribuidor_sin_iva' => CalculadoraPrecioArticulo::precioVentaFinal(
                            (float) $articulo->costo_con_descuento,
                            (string) ($articulo->utilidad_distribuidor_porcentaje ?? $utilidades[$articulo->catalogo_id]),
                            ObjetoImpuesto::tryFrom((string) $articulo->objeto_imp),
                        ),
                    ]);
                }
            });
        });
    }

    public function down(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->dropColumn(['utilidad_distribuidor_porcentaje', 'precio_distribuidor_sin_iva']);
        });

        Schema::table('catalogos', function (Blueprint $table) {
            $table->dropColumn('utilidad_distribuidor_porcentaje');
        });
    }
};
