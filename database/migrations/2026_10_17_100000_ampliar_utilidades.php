<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 036: las utilidades pasan de decimal(5,2) (tope 999.99%) a
     * decimal(7,2) para admitir hasta 9,999.99%, p. ej. una moldura de $10
     * que se vende como sello en $200. Solo agranda: no toca los datos.
     */
    public function up(): void
    {
        Schema::table('catalogos', function (Blueprint $table) {
            $table->decimal('utilidad_porcentaje', 7, 2)->default(0)->change();
            $table->decimal('utilidad_distribuidor_porcentaje', 7, 2)->default(0)->change();
        });

        Schema::table('articulos', function (Blueprint $table) {
            $table->decimal('utilidad_porcentaje', 7, 2)->nullable()->change();
            $table->decimal('utilidad_distribuidor_porcentaje', 7, 2)->nullable()->change();
        });
    }

    /**
     * Falla si algún porcentaje ya pasa de 999.99.
     */
    public function down(): void
    {
        Schema::table('catalogos', function (Blueprint $table) {
            $table->decimal('utilidad_porcentaje', 5, 2)->default(0)->change();
            $table->decimal('utilidad_distribuidor_porcentaje', 5, 2)->default(0)->change();
        });

        Schema::table('articulos', function (Blueprint $table) {
            $table->decimal('utilidad_porcentaje', 5, 2)->nullable()->change();
            $table->decimal('utilidad_distribuidor_porcentaje', 5, 2)->nullable()->change();
        });
    }
};
