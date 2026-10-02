<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 022, corrección 1: la orden de trabajo sigue a la entrega de la venta. Las
 * ventas que ya se entregaron (por escaneo, antes de la corrección) pasan su
 * orden a entregado, sin importar en qué estado se quedó.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ordenes_trabajo')
            ->whereIn('pedido_id', DB::table('pedidos')->select('id')->where('estado', 'entregado'))
            ->update(['estado' => 'entregado', 'updated_at' => now()]);
    }

    /**
     * No se revierte: el estado anterior de cada orden no se guardó.
     */
    public function down(): void {}
};
