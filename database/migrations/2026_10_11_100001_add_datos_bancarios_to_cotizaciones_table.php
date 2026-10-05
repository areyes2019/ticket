<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 027: foto de los datos bancarios visibles al crear la cotización. No se
 * rellena hacia atrás: las anteriores (null) se imprimen sin el bloque.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->json('datos_bancarios')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropColumn('datos_bancarios');
        });
    }
};
