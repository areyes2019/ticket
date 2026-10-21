<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El contador de ventas del día (039) busca en cada pantalla los pagos de
 * hoy: con este índice va directo a ellos sin revisar todos los pagos.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pedido_pagos', function (Blueprint $table) {
            $table->index('fecha_pago');
        });

        Schema::table('cotizacion_pagos', function (Blueprint $table) {
            $table->index('fecha_pago');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pedido_pagos', function (Blueprint $table) {
            $table->dropIndex(['fecha_pago']);
        });

        Schema::table('cotizacion_pagos', function (Blueprint $table) {
            $table->dropIndex(['fecha_pago']);
        });
    }
};
