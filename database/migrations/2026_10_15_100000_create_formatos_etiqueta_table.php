<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formatos de planilla para las etiquetas de producción (031): las medidas de
 * la etiqueta, sus separaciones y los márgenes de la hoja, con nombre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formatos_etiqueta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nombre', 60);
            $table->decimal('ancho_mm', 5, 1);
            $table->decimal('alto_mm', 5, 1);
            $table->decimal('separacion_horizontal_mm', 5, 1);
            $table->decimal('separacion_vertical_mm', 5, 1);
            $table->decimal('margen_superior_mm', 5, 1);
            $table->decimal('margen_izquierdo_mm', 5, 1);
            $table->boolean('es_predeterminado')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formatos_etiqueta');
    }
};
