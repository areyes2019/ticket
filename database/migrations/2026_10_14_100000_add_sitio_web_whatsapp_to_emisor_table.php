<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sitio web y WhatsApp del emisor, para el PDF de la cotización. La fila que ya
 * exista recibe los de Sello Pronto; luego se editan en Configuración.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emisor', function (Blueprint $table) {
            $table->string('sitio_web')->nullable()->after('telefono');
            $table->string('whatsapp', 13)->nullable()->after('sitio_web');
        });

        DB::table('emisor')->update([
            'sitio_web' => 'www.sellopronto.com.mx',
            'whatsapp' => '+524613581090',
        ]);
    }

    public function down(): void
    {
        Schema::table('emisor', function (Blueprint $table) {
            $table->dropColumn(['sitio_web', 'whatsapp']);
        });
    }
};
