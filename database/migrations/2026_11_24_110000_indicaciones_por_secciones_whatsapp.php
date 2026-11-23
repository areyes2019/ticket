<?php

use App\Enums\AgenteWhatsapp;
use App\Services\Whatsapp\TextoIndicaciones;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indicaciones de los agentes por secciones (045, adición 1): el cuadro único
 * de cada agente pasa a una caja JSON y su texto se conserva completo en
 * "Otras indicaciones" (regla 19).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_numeros', function (Blueprint $table) {
            $table->json('indicaciones_ventas')->nullable()->after('facturacion_activo');
            $table->json('indicaciones_facturacion')->nullable()->after('indicaciones_ventas');
        });

        DB::table('whatsapp_numeros')->orderBy('id')->each(function (object $numero) {
            $cambios = [];

            foreach (AgenteWhatsapp::cases() as $agente) {
                $caja = TextoIndicaciones::normalizar($agente, []);
                $caja['otras'] = TextoIndicaciones::limpiar($numero->{'instrucciones_'.$agente->value});
                $cambios[$agente->columnaIndicaciones()] = json_encode($caja, JSON_UNESCAPED_UNICODE);
            }

            DB::table('whatsapp_numeros')->where('id', $numero->id)->update($cambios);
        });

        Schema::table('whatsapp_numeros', function (Blueprint $table) {
            $table->dropColumn(['instrucciones_ventas', 'instrucciones_facturacion']);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_numeros', function (Blueprint $table) {
            $table->text('instrucciones_ventas')->nullable()->after('facturacion_activo');
            $table->text('instrucciones_facturacion')->nullable()->after('instrucciones_ventas');
        });

        DB::table('whatsapp_numeros')->orderBy('id')->each(function (object $numero) {
            $cambios = [];

            foreach (AgenteWhatsapp::cases() as $agente) {
                $caja = json_decode((string) $numero->{$agente->columnaIndicaciones()}, true) ?: [];
                $cambios['instrucciones_'.$agente->value] = TextoIndicaciones::armar($agente, $caja);
            }

            DB::table('whatsapp_numeros')->where('id', $numero->id)->update($cambios);
        });

        Schema::table('whatsapp_numeros', function (Blueprint $table) {
            $table->dropColumn(['indicaciones_ventas', 'indicaciones_facturacion']);
        });
    }
};
