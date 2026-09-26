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
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('rfc', 13);
            $table->string('razon_social');
            $table->string('regimen_fiscal', 3);
            $table->string('codigo_postal_fiscal', 5);
            $table->string('nombre_comercial')->nullable();
            $table->string('nombre_contacto')->nullable();
            $table->string('correo')->nullable();
            $table->string('telefono', 13)->nullable();
            $table->string('direccion_comercial')->nullable();
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
        Schema::dropIfExists('clientes');
    }
};
