<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 029: el primer pago de una cotización decide si nace su venta y su
     * orden de trabajo. requiere_produccion marca el catálogo (todos sus
     * artículos lo heredan); cobro_en_cotizacion marca las ventas que leen sus
     * pagos de la cotización (las de "Aceptar", 021, quedan en false);
     * registrado_al_entregar marca el cobro del saldo que hizo "Entregado".
     */
    public function up(): void
    {
        Schema::table('catalogos', function (Blueprint $table) {
            $table->boolean('requiere_produccion')->default(false)->after('utilidad_distribuidor_porcentaje');
        });

        Schema::table('pedidos', function (Blueprint $table) {
            $table->boolean('cobro_en_cotizacion')->default(false)->after('cliente_id');
        });

        Schema::table('cotizacion_pagos', function (Blueprint $table) {
            $table->boolean('registrado_al_entregar')->default(false)->after('cuenta_id');
        });
    }

    public function down(): void
    {
        Schema::table('cotizacion_pagos', function (Blueprint $table) {
            $table->dropColumn('registrado_al_entregar');
        });

        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropColumn('cobro_en_cotizacion');
        });

        Schema::table('catalogos', function (Blueprint $table) {
            $table->dropColumn('requiere_produccion');
        });
    }
};
