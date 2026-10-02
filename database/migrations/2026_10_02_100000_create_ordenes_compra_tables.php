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
        // Contador del folio de orden de compra: nunca se reutiliza un folio
        // aunque se borre la orden que lo tenía.
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('ultimo_folio_orden_compra')->default(0)->after('ultimo_folio_factura');
        });

        Schema::create('ordenes_compra', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            // Sin acción al borrar: los proveedores usan soft delete.
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->foreignId('duplicada_de_id')->nullable()->constrained('ordenes_compra')->nullOnDelete();
            $table->unsignedInteger('folio');
            $table->string('estado', 10)->default('borrador');
            $table->date('fecha_entrega_esperada')->nullable();
            $table->text('observaciones')->nullable();
            $table->string('descuento_global_tipo', 10)->nullable();
            $table->decimal('descuento_global_valor', 12, 2)->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('total_descuento', 14, 2)->default(0);
            $table->decimal('base_iva_16', 14, 2)->default(0);
            $table->decimal('total_iva_16', 14, 2)->default(0);
            $table->decimal('base_iva_0', 14, 2)->default(0);
            $table->decimal('base_exento', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            // Pago único de contado: los dos nulos = sin pagar.
            $table->foreignId('cuenta_id')->nullable()->constrained('cuentas')->restrictOnDelete();
            $table->date('fecha_pago')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'folio']);
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'estado']);
            $table->index(['proveedor_id', 'estado']);
        });

        Schema::create('orden_compra_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_compra_id')->constrained('ordenes_compra')->cascadeOnDelete();
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
            $table->timestamps();
        });

        // Las órdenes activas ahora se calculan (Proveedor::tieneOrdenesActivas()).
        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropColumn('tiene_ordenes_activas');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->boolean('tiene_ordenes_activas')->default(false);
        });

        Schema::dropIfExists('orden_compra_lineas');
        Schema::dropIfExists('ordenes_compra');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ultimo_folio_orden_compra');
        });
    }
};
