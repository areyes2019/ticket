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
        Schema::create('ordenes_trabajo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Una venta, una orden; se va con su venta (022).
            $table->foreignId('pedido_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('estado', 20)->default('en_dibujo');
            $table->string('imagen_ruta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'estado']);
        });

        Schema::create('orden_trabajo_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_trabajo_id')->constrained('ordenes_trabajo')->cascadeOnDelete();
            // Modelo, nombre y cantidad se leen de la línea de la venta; aquí solo el color.
            $table->foreignId('pedido_linea_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('color_tinta', 20);
            $table->string('color_tinta_otro', 40)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orden_trabajo_lineas');
        Schema::dropIfExists('ordenes_trabajo');
    }
};
