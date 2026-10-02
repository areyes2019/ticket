<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Etiquetas de TipoPago, fijas aquí para que la migración no dependa del enum.
     */
    private const ETIQUETAS_PAGO = [
        'anticipo' => 'Anticipo',
        'saldo' => 'Saldo',
        'pago_total' => 'Pago total',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cuentas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('nombre', 100);
            $table->string('tipo', 10);
            $table->decimal('saldo_inicial', 14, 2);
            $table->decimal('saldo_actual', 14, 2);
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'nombre']);
        });

        Schema::create('movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            // restrict: una cuenta con movimientos no se borra.
            $table->foreignId('cuenta_id')->constrained('cuentas')->restrictOnDelete();
            $table->string('tipo', 15);
            // Con signo: el efecto sobre el saldo de la cuenta.
            $table->decimal('monto', 14, 2);
            $table->date('fecha');
            $table->string('concepto');
            $table->nullableMorphs('documentable');
            $table->uuid('transferencia_id')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'fecha']);
            $table->index(['cuenta_id', 'fecha']);
        });

        Schema::table('cotizacion_pagos', function (Blueprint $table) {
            $table->foreignId('cuenta_id')->nullable()->after('cotizacion_id')->constrained('cuentas')->restrictOnDelete();
        });

        $this->pasarPagosACajaGeneral();

        Schema::table('cotizacion_pagos', function (Blueprint $table) {
            $table->foreignId('cuenta_id')->nullable(false)->change();
            $table->dropColumn('forma_pago');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cotizacion_pagos', function (Blueprint $table) {
            $table->string('forma_pago', 2)->default('99')->after('monto');
        });

        Schema::table('cotizacion_pagos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cuenta_id');
        });

        Schema::dropIfExists('movimientos');
        Schema::dropIfExists('cuentas');
    }

    /**
     * Los pagos registrados antes de Tesorería quedan en una cuenta "Caja
     * General" por usuario, cada uno con su ingreso, para que el saldo cuadre.
     */
    private function pasarPagosACajaGeneral(): void
    {
        $pagos = DB::table('cotizacion_pagos')
            ->join('cotizaciones', 'cotizaciones.id', '=', 'cotizacion_pagos.cotizacion_id')
            ->select('cotizacion_pagos.*', 'cotizaciones.user_id', 'cotizaciones.folio')
            ->orderBy('cotizacion_pagos.id')
            ->get()
            ->groupBy('user_id');

        $ahora = now();

        foreach ($pagos as $userId => $pagosDelUsuario) {
            $cuentaId = DB::table('cuentas')->insertGetId([
                'user_id' => $userId,
                'nombre' => 'Caja General',
                'tipo' => 'efectivo',
                'saldo_inicial' => 0,
                'saldo_actual' => 0,
                'activa' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);

            foreach ($pagosDelUsuario as $pago) {
                DB::table('cotizacion_pagos')->where('id', $pago->id)->update(['cuenta_id' => $cuentaId]);

                DB::table('movimientos')->insert([
                    'user_id' => $userId,
                    'cuenta_id' => $cuentaId,
                    'tipo' => 'ingreso',
                    'monto' => $pago->monto,
                    'fecha' => $pago->fecha_pago,
                    'concepto' => (self::ETIQUETAS_PAGO[$pago->tipo] ?? Str::headline($pago->tipo))
                        .' de Cotización COT-'.str_pad((string) $pago->folio, 4, '0', STR_PAD_LEFT),
                    'documentable_type' => 'cotizacion_pago',
                    'documentable_id' => $pago->id,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }

            DB::table('cuentas')->where('id', $cuentaId)->update([
                'saldo_actual' => DB::table('movimientos')->where('cuenta_id', $cuentaId)->sum('monto'),
            ]);
        }
    }
};
