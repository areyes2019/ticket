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
        // Contador del folio de cotización: nunca se reutiliza un folio aunque
        // se borre la cotización que lo tenía.
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('ultimo_folio_cotizacion')->default(0)->after('estado');
        });

        Schema::create('cotizaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('cliente_id')->constrained();
            $table->unsignedInteger('folio');
            $table->string('estado', 20)->default('borrador');
            $table->string('descuento_global_tipo', 10)->nullable();
            $table->decimal('descuento_global_valor', 12, 2)->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('total_descuento', 14, 2)->default(0);
            $table->decimal('base_iva_16', 14, 2)->default(0);
            $table->decimal('total_iva_16', 14, 2)->default(0);
            $table->decimal('base_iva_0', 14, 2)->default(0);
            $table->decimal('base_exento', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'folio']);
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'estado']);
            $table->index('updated_at');
        });

        Schema::create('cotizacion_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cotizacion_id')->constrained('cotizaciones')->cascadeOnDelete();
            $table->unsignedSmallInteger('orden');
            // Sin acción al borrar: los artículos usan soft delete.
            $table->foreignId('articulo_id')->nullable()->constrained();
            $table->unsignedInteger('cantidad');
            $table->string('descripcion');
            $table->string('modelo')->nullable();
            $table->decimal('precio_unitario', 10, 2);
            $table->string('descuento_tipo', 10)->nullable();
            $table->decimal('descuento_valor', 12, 2)->nullable();
            $table->string('tasa_iva', 6);
            $table->decimal('importe', 14, 2);
            $table->decimal('iva_importe', 14, 2);
            $table->decimal('costo_unitario', 10, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('cotizacion_pagos', function (Blueprint $table) {
            $table->id();
            // restrict: una cotización con pagos no se borra (ver Cotizacion::puedeEliminarse()).
            $table->foreignId('cotizacion_id')->constrained('cotizaciones')->restrictOnDelete();
            $table->string('tipo', 12);
            $table->date('fecha_pago');
            $table->decimal('monto', 14, 2);
            $table->string('forma_pago', 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cotizacion_pagos');
        Schema::dropIfExists('cotizacion_lineas');
        Schema::dropIfExists('cotizaciones');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ultimo_folio_cotizacion');
        });
    }
};
