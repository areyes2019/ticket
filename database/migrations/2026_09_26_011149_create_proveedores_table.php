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
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('nombre_comercial');
            $table->string('nombre_contacto')->nullable();
            $table->string('correo')->nullable();
            $table->string('telefono', 13)->nullable();
            $table->string('rfc', 13)->nullable();
            $table->boolean('tiene_ordenes_activas')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'rfc']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proveedores');
    }
};
