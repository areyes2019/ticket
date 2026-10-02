<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Contador del folio de pedido (el "No. de ticket"): nunca se reutiliza.
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('ultimo_folio_pedido')->default(0)->after('ultimo_folio_orden_compra');
        });

        Schema::create('pedidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->unsignedInteger('folio');
            $table->string('estado', 15)->default('pendiente');
            // El cliente de mostrador vive en el pedido: sin RFC ni catálogo.
            $table->string('cliente_nombre', 150);
            $table->string('cliente_telefono', 15);
            $table->string('cliente_correo')->nullable();
            $table->string('descuento_global_tipo', 10)->nullable();
            $table->decimal('descuento_global_valor', 12, 2)->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('total_descuento', 14, 2)->default(0);
            $table->decimal('base_iva_16', 14, 2)->default(0);
            $table->decimal('total_iva_16', 14, 2)->default(0);
            $table->decimal('base_iva_0', 14, 2)->default(0);
            $table->decimal('base_exento', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamp('entregado_en')->nullable();
            $table->string('autofactura_token', 64)->nullable()->unique();
            $table->text('autofactura_error')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'folio']);
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'estado']);
            $table->index(['user_id', 'cliente_telefono']);
        });

        Schema::create('pedido_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('orden');
            // Sin acción al borrar: los artículos usan soft delete. Null = línea libre.
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

        Schema::create('pedido_pagos', function (Blueprint $table) {
            $table->id();
            // restrict: un pedido con pagos no se borra (ver Pedido::puedeEliminarse()).
            $table->foreignId('pedido_id')->constrained()->restrictOnDelete();
            $table->foreignId('cuenta_id')->constrained()->restrictOnDelete();
            $table->date('fecha_pago');
            $table->decimal('monto', 14, 2);
            $table->boolean('registrado_al_entregar')->default(false);
            $table->timestamps();
        });

        Schema::create('configuraciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('clave', 50);
            $table->text('valor')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'clave']);
        });

        Schema::table('facturas', function (Blueprint $table) {
            $table->foreignId('pedido_id')->nullable()->after('cotizacion_id')->constrained()->nullOnDelete();
        });

        // La autofactura de un pedido copia sus líneas libres (019).
        Schema::table('factura_lineas', function (Blueprint $table) {
            $table->foreignId('articulo_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('factura_lineas')->whereNull('articulo_id')->exists()) {
            throw new RuntimeException('Hay líneas de factura sin artículo (autofacturas de pedidos): no se puede volver a exigir el artículo sin borrar facturas.');
        }

        Schema::table('factura_lineas', function (Blueprint $table) {
            $table->foreignId('articulo_id')->nullable(false)->change();
        });

        Schema::table('facturas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pedido_id');
        });

        Schema::dropIfExists('configuraciones');
        Schema::dropIfExists('pedido_pagos');
        Schema::dropIfExists('pedido_lineas');
        Schema::dropIfExists('pedidos');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ultimo_folio_pedido');
        });
    }
};
