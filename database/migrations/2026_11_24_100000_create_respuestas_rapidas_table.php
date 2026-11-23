<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Respuestas rápidas del chat de WhatsApp (043): del negocio, sin user_id,
     * como los datos bancarios; se borran para siempre (sin softDeletes).
     */
    public function up(): void
    {
        Schema::create('respuestas_rapidas', function (Blueprint $table) {
            $table->id();
            $table->string('atajo', 30)->unique();
            $table->text('texto');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('respuestas_rapidas');
    }
};
