<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agentes de WhatsApp (041): el número conectado con sus ajustes, las
 * conversaciones, sus mensajes, las plantillas aprobadas en Meta y el gasto
 * de la API de Claude.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_numeros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('phone_number_id', 32)->unique();
            $table->string('waba_id', 32);
            $table->string('numero_visible', 20)->nullable();
            $table->string('nombre_visible')->nullable();
            $table->text('token');
            $table->text('app_secret');
            $table->string('verify_token', 64);

            // Ajustes de los agentes (regla 81).
            $table->boolean('ventas_activo')->default(false);
            $table->boolean('facturacion_activo')->default(false);
            $table->text('instrucciones_ventas')->nullable();
            $table->text('instrucciones_facturacion')->nullable();
            $table->json('horario')->nullable();
            $table->string('aviso_humano', 500)->nullable();
            $table->string('aviso_humano_fuera_horario', 500)->nullable();
            $table->decimal('tope_mensual_usd', 8, 2)->default(50);
            $table->unsignedSmallInteger('limite_hora')->default(20);
            $table->unsignedSmallInteger('limite_dia')->default(100);
            $table->timestamps();
        });

        Schema::create('whatsapp_conversaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_numero_id')->constrained('whatsapp_numeros')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('wa_id', 20);
            $table->string('telefono_10', 10)->index();
            $table->string('nombre_perfil')->nullable();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->string('estado', 10)->default('agente');
            $table->string('agente_actual', 12)->default('ventas');
            $table->string('motivo_humano')->nullable();
            $table->boolean('aviso_humano_enviado')->default(false);
            $table->boolean('sin_limite')->default(false);
            $table->date('aviso_audio_el')->nullable();
            // Ventas que el agente de facturación ya validó (regla 44), entre turnos.
            $table->json('ventas_validadas')->nullable();
            $table->timestamp('ultimo_mensaje_cliente_en')->nullable();
            $table->timestamp('ultimo_mensaje_en')->nullable();
            $table->unsignedInteger('pendientes_humano')->default(0);
            $table->text('resumen')->nullable();
            $table->unsignedBigInteger('resumen_hasta_mensaje_id')->nullable();
            $table->timestamps();

            $table->unique(['whatsapp_numero_id', 'wa_id']);
            $table->index(['user_id', 'estado', 'ultimo_mensaje_en']);
        });

        Schema::create('whatsapp_mensajes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_conversacion_id')->constrained('whatsapp_conversaciones')->cascadeOnDelete();
            $table->string('wamid', 128)->nullable()->unique();
            $table->string('direccion', 8);
            $table->string('autor', 12);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tipo', 16);
            $table->text('texto')->nullable();
            $table->string('archivo_ruta')->nullable();
            $table->string('archivo_nombre')->nullable();
            $table->string('archivo_mime', 100)->nullable();
            $table->json('datos')->nullable();
            $table->string('estado_envio', 10)->nullable();
            $table->string('error_envio')->nullable();
            $table->boolean('respondido')->default(false);
            $table->timestamp('enviado_en')->nullable();
            $table->timestamps();

            $table->index(['whatsapp_conversacion_id', 'id']);
        });

        Schema::create('whatsapp_plantillas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_numero_id')->constrained('whatsapp_numeros')->cascadeOnDelete();
            $table->string('nombre');
            $table->string('idioma', 12);
            $table->string('categoria', 20)->nullable();
            $table->text('cuerpo');
            $table->unsignedTinyInteger('variables')->default(0);
            $table->string('estado', 20);
            $table->timestamps();

            $table->unique(['whatsapp_numero_id', 'nombre', 'idioma']);
        });

        Schema::create('whatsapp_uso_claude', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_conversacion_id')->nullable()->constrained('whatsapp_conversaciones')->nullOnDelete();
            $table->string('modelo', 40);
            $table->unsignedInteger('tokens_entrada')->default(0);
            $table->unsignedInteger('tokens_salida')->default(0);
            $table->unsignedInteger('tokens_cache_lectura')->default(0);
            $table->unsignedInteger('tokens_cache_escritura')->default(0);
            $table->decimal('costo_usd', 10, 6)->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
        });

        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->text('notas')->nullable();
            $table->string('contacto_nombre')->nullable();
            $table->string('contacto_telefono', 20)->nullable();
            $table->foreignId('whatsapp_conversacion_id')->nullable()->constrained('whatsapp_conversaciones')->nullOnDelete();
        });

        Schema::table('facturas', function (Blueprint $table) {
            $table->foreignId('whatsapp_conversacion_id')->nullable()->constrained('whatsapp_conversaciones')->nullOnDelete();
        });

        Schema::table('clientes', function (Blueprint $table) {
            $table->string('telefono_10', 10)->nullable();
            $table->index(['user_id', 'telefono_10']);
        });

        // Los teléfonos que ya existen (regla 76): últimos 10 dígitos.
        DB::table('clientes')->whereNotNull('telefono')->orderBy('id')->each(function (object $cliente) {
            $digitos = substr((string) preg_replace('/\D/', '', $cliente->telefono), -10);

            DB::table('clientes')->where('id', $cliente->id)->update(['telefono_10' => strlen($digitos) === 10 ? $digitos : null]);
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'telefono_10']);
            $table->dropColumn('telefono_10');
        });

        Schema::table('facturas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('whatsapp_conversacion_id');
        });

        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('whatsapp_conversacion_id');
            $table->dropColumn(['notas', 'contacto_nombre', 'contacto_telefono']);
        });

        Schema::dropIfExists('whatsapp_uso_claude');
        Schema::dropIfExists('whatsapp_plantillas');
        Schema::dropIfExists('whatsapp_mensajes');
        Schema::dropIfExists('whatsapp_conversaciones');
        Schema::dropIfExists('whatsapp_numeros');
    }
};
