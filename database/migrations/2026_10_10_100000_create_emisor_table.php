<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 026: datos del emisor para los PDF. Una sola fila para toda la instalación
 * (todos timbran con la misma llave de facturapi.io), sin user_id y con todas
 * las columnas nullable: un emisor incompleto avisa, no bloquea. Se crea vacía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emisor', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->nullable();
            $table->string('rfc', 13)->nullable();
            $table->string('regimen_fiscal', 3)->nullable();
            $table->string('domicilio')->nullable();
            $table->string('correo')->nullable();
            $table->string('telefono', 13)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emisor');
    }
};
