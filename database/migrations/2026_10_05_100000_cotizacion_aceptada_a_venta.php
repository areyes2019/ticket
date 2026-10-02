<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // La venta (pedido) que nace de una cotización aceptada: una cotización,
        // una venta. restrict: la aceptada no se borra por regla (021).
        Schema::table('pedidos', function (Blueprint $table) {
            $table->foreignId('cotizacion_id')->nullable()->unique()->after('user_id')
                ->constrained('cotizaciones')->restrictOnDelete();
            $table->foreignId('cliente_id')->nullable()->after('cotizacion_id')
                ->constrained('clientes')->nullOnDelete();
        });

        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->timestamp('aceptada_en')->nullable()->after('estado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('cotizaciones')->where('estado', 'aceptada')->exists()) {
            throw new RuntimeException('Hay cotizaciones aceptadas: el estado "aceptada" no existe antes de esta migración.');
        }

        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropColumn('aceptada_en');
        });

        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cliente_id');
            $table->dropConstrainedForeignId('cotizacion_id');
        });
    }
};
