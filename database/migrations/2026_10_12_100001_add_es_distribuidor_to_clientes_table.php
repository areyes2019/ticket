<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 028: el cliente distribuidor cotiza y factura con el precio
     * distribuidor. Los existentes quedan sin marcar; ningún documento
     * guardado se recalcula.
     */
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->boolean('es_distribuidor')->default(false)->after('descuento_permanente');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('es_distribuidor');
        });
    }
};
