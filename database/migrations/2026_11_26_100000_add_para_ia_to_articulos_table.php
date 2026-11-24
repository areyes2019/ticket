<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 047, adición 1: la lista "Artículos IA" que el agente de ventas consulta
 * primero. Ningún artículo existente queda marcado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->boolean('para_ia')->default(false);
            $table->index(['user_id', 'para_ia']);
        });
    }

    public function down(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'para_ia']);
            $table->dropColumn('para_ia');
        });
    }
};
