<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 027: cuentas a las que el cliente paga, impresas en la cotización. Del
 * negocio, no de cada usuario (sin user_id), y sin relación con las Cuentas
 * de Tesorería. Los números son texto: una cuenta puede empezar con cero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datos_bancarios', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_banco', 100);
            $table->string('beneficiario', 150)->nullable();
            $table->string('numero_cuenta', 20)->nullable();
            $table->string('tarjeta', 16)->nullable();
            $table->string('clabe', 18)->nullable();
            $table->string('logo_ruta')->nullable();
            $table->boolean('visible_en_cotizaciones')->default(true);
            $table->unsignedInteger('orden');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datos_bancarios');
    }
};
