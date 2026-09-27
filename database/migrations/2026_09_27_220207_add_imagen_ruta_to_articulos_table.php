<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ruta de la imagen dentro del disco privado; null es "sin imagen". Sin
     * índice: nunca se filtra por ella.
     */
    public function up(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->string('imagen_ruta')->nullable()->after('precio_unitario_sin_iva');
        });
    }

    public function down(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->dropColumn('imagen_ruta');
        });
    }
};
