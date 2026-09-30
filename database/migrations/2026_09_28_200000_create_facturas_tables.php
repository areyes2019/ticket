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
        // Contador del folio interno de factura: nunca se reutiliza.
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('ultimo_folio_factura')->default(0)->after('ultimo_folio_cotizacion');
        });

        Schema::create('facturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('cliente_id')->constrained();
            $table->unsignedInteger('folio');
            $table->string('estado', 20)->default('borrador');
            $table->string('uso_cfdi', 4);
            $table->string('forma_pago', 2);
            $table->string('metodo_pago', 3);
            $table->string('descuento_global_tipo', 10)->nullable();
            $table->decimal('descuento_global_valor', 12, 2)->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('total_descuento', 14, 2)->default(0);
            $table->decimal('base_iva_16', 14, 2)->default(0);
            $table->decimal('total_iva_16', 14, 2)->default(0);
            $table->decimal('base_iva_0', 14, 2)->default(0);
            $table->decimal('base_exento', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);

            // Timbrado: respuesta de facturapi.io.
            $table->string('facturapi_invoice_id', 50)->nullable();
            $table->string('uuid_fiscal', 36)->nullable();
            $table->string('facturapi_serie', 25)->nullable();
            $table->unsignedInteger('facturapi_folio')->nullable();
            $table->text('sello_cfdi')->nullable();
            $table->text('sello_sat')->nullable();
            $table->string('no_certificado_sat', 20)->nullable();
            $table->dateTime('fecha_timbrado')->nullable();
            $table->text('cadena_original_sat')->nullable();
            $table->string('version_comprobante', 5)->nullable();
            $table->text('url_verificacion_sat')->nullable();

            // Copias fiscales tomadas al timbrar: el PDF lee de aquí.
            $table->string('receptor_rfc', 13)->nullable();
            $table->string('receptor_razon_social')->nullable();
            $table->string('receptor_regimen_fiscal', 3)->nullable();
            $table->string('receptor_codigo_postal', 5)->nullable();
            $table->string('receptor_correo')->nullable();
            $table->string('emisor_rfc', 13)->nullable();
            $table->string('emisor_razon_social')->nullable();
            $table->string('emisor_regimen_fiscal', 3)->nullable();
            $table->string('lugar_expedicion', 5)->nullable();

            $table->text('error_timbrado')->nullable();
            $table->string('tipo_error_timbrado', 10)->nullable();

            $table->string('motivo_cancelacion', 2)->nullable();
            $table->foreignId('factura_sustituta_id')->nullable()->constrained('facturas')->restrictOnDelete();
            $table->string('estado_cancelacion', 10)->nullable();
            $table->dateTime('fecha_cancelacion')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'folio']);
            $table->index(['user_id', 'estado']);
            $table->index(['user_id', 'created_at']);
            $table->index('uuid_fiscal');
        });

        Schema::create('factura_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factura_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('orden');
            // Sin acción al borrar: los artículos usan soft delete.
            $table->foreignId('articulo_id')->constrained();
            $table->unsignedInteger('cantidad');
            $table->string('descripcion');
            $table->string('modelo');
            $table->decimal('precio_unitario', 10, 2);
            $table->string('descuento_tipo', 10)->nullable();
            $table->decimal('descuento_valor', 12, 2)->nullable();
            $table->string('tasa_iva', 6);
            $table->decimal('importe', 14, 2);
            $table->decimal('iva_importe', 14, 2);
            $table->string('clave_prod_serv', 8);
            $table->string('clave_unidad', 3);
            $table->string('objeto_imp', 2);
            $table->timestamps();
        });

        Schema::create('complementos_pago', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factura_id')->unique()->constrained()->restrictOnDelete();
            $table->date('fecha_pago');
            $table->decimal('monto', 14, 2);
            $table->string('forma_pago', 2);
            $table->string('estado', 10)->default('pendiente');
            $table->string('facturapi_invoice_id', 50)->nullable();
            $table->string('uuid_fiscal', 36)->nullable();
            $table->text('sello_cfdi')->nullable();
            $table->text('sello_sat')->nullable();
            $table->text('cadena_original_sat')->nullable();
            $table->dateTime('fecha_timbrado')->nullable();
            $table->text('error_timbrado')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('complementos_pago');
        Schema::dropIfExists('factura_lineas');
        Schema::dropIfExists('facturas');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ultimo_folio_factura');
        });
    }
};
