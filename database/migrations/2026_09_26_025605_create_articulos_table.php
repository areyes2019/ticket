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
        Schema::create('articulos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->string('nombre');
            $table->string('modelo');
            $table->string('clave_prod_serv', 8);
            $table->string('clave_unidad', 3);
            $table->string('objeto_imp', 2);
            $table->decimal('precio_unitario_sin_iva', 10, 2);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'nombre']);
            $table->index(['proveedor_id', 'nombre']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('articulos');
    }
};
