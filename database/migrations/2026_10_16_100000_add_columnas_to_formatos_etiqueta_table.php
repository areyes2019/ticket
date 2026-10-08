<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columnas elegidas del formato de planilla (031, corrección 2): 1, 2 o 3;
 * null es automático, como quedan los formatos que ya existían.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('formatos_etiqueta', function (Blueprint $table) {
            $table->unsignedTinyInteger('columnas')->nullable()->after('margen_izquierdo_mm');
        });
    }

    public function down(): void
    {
        Schema::table('formatos_etiqueta', function (Blueprint $table) {
            $table->dropColumn('columnas');
        });
    }
};
