<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 046, adición 1: el horario de atención pasa del número de WhatsApp al
 * negocio (la fila única de `emisor`), con el horario que ya estaba capturado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emisor', function (Blueprint $table) {
            $table->json('horario')->nullable();
        });

        $horario = DB::table('whatsapp_numeros')->whereNotNull('horario')->orderBy('id')->value('horario');

        if ($horario !== null) {
            $emisor = DB::table('emisor')->orderBy('id')->value('id');

            if ($emisor === null) {
                DB::table('emisor')->insert(['horario' => $horario, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('emisor')->where('id', $emisor)->update(['horario' => $horario]);
            }
        }

        Schema::table('whatsapp_numeros', function (Blueprint $table) {
            $table->dropColumn('horario');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_numeros', function (Blueprint $table) {
            $table->json('horario')->nullable();
        });

        $horario = DB::table('emisor')->orderBy('id')->value('horario');

        if ($horario !== null) {
            DB::table('whatsapp_numeros')->update(['horario' => $horario]);
        }

        Schema::table('emisor', function (Blueprint $table) {
            $table->dropColumn('horario');
        });
    }
};
