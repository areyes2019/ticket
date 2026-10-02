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
        // Una fila por artículo marcado "en existencias"; sin fila, el
        // artículo no es inventario. Quitarlo es borrado lógico: volver a
        // marcarlo restaura la misma fila (de ahí el único sobre articulo_id).
        Schema::create('existencias', function (Blueprint $table) {
            $table->id();
            // Sin acción al borrar: los artículos usan soft delete.
            $table->foreignId('articulo_id')->unique()->constrained();
            $table->unsignedInteger('existencia')->default(0);
            $table->unsignedInteger('faltante_pendiente')->default(0);
            $table->unsignedInteger('minimo')->default(0);
            $table->unsignedInteger('maximo')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('movimientos_inventario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            // Ligado al artículo, no a la fila de existencias: el historial
            // sobrevive a quitar y volver a marcar el artículo.
            $table->foreignId('articulo_id')->constrained();
            $table->string('tipo', 10);
            $table->string('motivo', 20);
            // Magnitud; la dirección la da el tipo. En un ajuste, la cantidad final.
            $table->unsignedInteger('cantidad');
            $table->unsignedInteger('existencia_resultante');
            $table->unsignedInteger('faltante_resultante');
            $table->text('nota')->nullable();
            $table->nullableMorphs('documentable');
            $table->timestamps();

            $table->index(['articulo_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
        Schema::dropIfExists('existencias');
    }
};
