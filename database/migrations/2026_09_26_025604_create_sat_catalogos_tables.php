<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogos del SAT que usa el sistema. Se llenan con `php artisan catalogos-sat:actualizar`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sat_claves_prod_serv', function (Blueprint $table) {
            $table->string('clave', 8)->primary();
            $table->string('descripcion');
        });

        Schema::create('sat_claves_unidad', function (Blueprint $table) {
            $table->string('clave', 3)->primary();
            $table->string('nombre');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sat_claves_unidad');
        Schema::dropIfExists('sat_claves_prod_serv');
    }
};
