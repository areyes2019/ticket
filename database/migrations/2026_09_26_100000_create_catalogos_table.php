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
        Schema::create('catalogos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->string('nombre');
            $table->decimal('descuento', 5, 2)->default(0);
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
        Schema::dropIfExists('catalogos');
    }
};
