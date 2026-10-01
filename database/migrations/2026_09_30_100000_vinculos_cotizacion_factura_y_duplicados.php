<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Sin unique: una cotización puede tener facturas canceladas y una vigente.
        Schema::table('facturas', function (Blueprint $table) {
            $table->foreignId('cotizacion_id')->nullable()->after('cliente_id')->constrained('cotizaciones')->nullOnDelete();
            $table->foreignId('duplicada_de_id')->nullable()->after('cotizacion_id')->constrained('facturas')->nullOnDelete();
        });

        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->foreignId('duplicada_de_id')->nullable()->after('cliente_id')->constrained('cotizaciones')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duplicada_de_id');
        });

        Schema::table('facturas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duplicada_de_id');
            $table->dropConstrainedForeignId('cotizacion_id');
        });
    }
};
