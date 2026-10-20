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
        Schema::create('reembolsos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // La venta (pedido) o la cotización sin venta: un solo reembolso por documento (038).
            $table->morphs('reembolsable');
            $table->decimal('monto', 12, 2);
            $table->string('motivo', 500);
            // La factura sin timbrar se descarta (se borra): el folio sobrevive.
            $table->foreignId('factura_id')->nullable()->constrained()->nullOnDelete();
            $table->string('factura_folio', 30)->nullable();
            $table->string('factura_decision', 10)->nullable();
            $table->string('motivo_cancelacion', 2)->nullable();
            $table->timestamps();

            $table->unique(['reembolsable_type', 'reembolsable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reembolsos');
    }
};
