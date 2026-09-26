<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega el catálogo y el precio con descuento, y pasa los artículos
     * existentes a un catálogo "General" (0%) de su mismo proveedor.
     */
    public function up(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->foreignId('catalogo_id')->nullable()->after('proveedor_id')->constrained('catalogos');
            $table->decimal('precio_con_descuento', 10, 2)->nullable()->after('precio_unitario_sin_iva');
        });

        // Incluye proveedores y artículos eliminados, para que ningún registro
        // quede sin catálogo.
        DB::transaction(function () {
            $proveedores = DB::table('proveedores')
                ->whereIn('id', DB::table('articulos')->select('proveedor_id'))
                ->get(['id', 'user_id']);

            foreach ($proveedores as $proveedor) {
                $catalogoId = DB::table('catalogos')->insertGetId([
                    'user_id' => $proveedor->user_id,
                    'proveedor_id' => $proveedor->id,
                    'nombre' => 'General',
                    'descuento' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('articulos')
                    ->where('proveedor_id', $proveedor->id)
                    ->update([
                        'catalogo_id' => $catalogoId,
                        'precio_con_descuento' => DB::raw('precio_unitario_sin_iva'),
                    ]);
            }
        });

        Schema::table('articulos', function (Blueprint $table) {
            $table->foreignId('catalogo_id')->nullable(false)->change();
            $table->decimal('precio_con_descuento', 10, 2)->nullable(false)->change();
        });
    }

    /**
     * La FK se borra antes que la columna: MySQL no deja borrar el índice que
     * sostiene una FK.
     */
    public function down(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->dropForeign(['catalogo_id']);
            $table->dropColumn(['catalogo_id', 'precio_con_descuento']);
        });
    }
};
