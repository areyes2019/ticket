<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 023: el descuento permanente del cliente y su copia congelada en cada
 * cotización. Todo lo existente queda en 0.00; ningún documento se recalcula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->decimal('descuento_permanente', 5, 2)->default(0)->after('direccion_comercial');
        });

        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->decimal('descuento_cliente_porcentaje', 5, 2)->default(0)->after('cliente_id');
        });
    }

    public function down(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropColumn('descuento_cliente_porcentaje');
        });

        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('descuento_permanente');
        });
    }
};
