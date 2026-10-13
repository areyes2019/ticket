<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 037: precio fijo capturado a mano (el que lee el cliente, con IVA si es
     * objeto 02) y la fecha en que se puso. Null = precio por utilidad, así
     * que ningún artículo existente cambia.
     */
    public function up(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->decimal('precio_fijo', 10, 2)->nullable()->after('utilidad_porcentaje');
            $table->date('precio_fijo_desde')->nullable()->after('precio_fijo');
        });
    }

    public function down(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->dropColumn(['precio_fijo', 'precio_fijo_desde']);
        });
    }
};
