<?php

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\Movimiento;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('pasa los pagos existentes a una Caja General por usuario, con su ingreso', function () {
    $migracion = require database_path('migrations/2026_10_01_100000_create_tesoreria_tables.php');
    $migracion->down();

    $ana = User::factory()->create();
    $beto = User::factory()->create();
    $sinPagos = User::factory()->create();
    $deAna = Cotizacion::factory()->for(Cliente::factory()->for($ana))->create(['user_id' => $ana->id, 'folio' => 12]);
    $deBeto = Cotizacion::factory()->for(Cliente::factory()->for($beto))->create(['user_id' => $beto->id, 'folio' => 3]);
    Cotizacion::factory()->for(Cliente::factory()->for($sinPagos))->create(['user_id' => $sinPagos->id]);

    $pago = fn (Cotizacion $c, string $tipo, string $monto) => DB::table('cotizacion_pagos')->insertGetId([
        'cotizacion_id' => $c->id, 'tipo' => $tipo, 'fecha_pago' => '2026-09-20', 'monto' => $monto, 'forma_pago' => '03',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $anticipo = $pago($deAna, 'anticipo', '100.00');
    $pago($deAna, 'saldo', '250.50');
    $pago($deBeto, 'pago_total', '40.00');

    $migracion->up();

    expect(Schema::hasColumn('cotizacion_pagos', 'forma_pago'))->toBeFalse()
        ->and(Cuenta::where('user_id', $sinPagos->id)->exists())->toBeFalse();

    $caja = Cuenta::where('user_id', $ana->id)->sole();
    expect($caja->only(['nombre', 'saldo_inicial', 'saldo_actual', 'activa']))
        ->toBe(['nombre' => 'Caja General', 'saldo_inicial' => '0.00', 'saldo_actual' => '350.50', 'activa' => true])
        ->and(DB::table('cotizacion_pagos')->where('cotizacion_id', $deAna->id)->pluck('cuenta_id')->unique()->all())->toBe([$caja->id])
        ->and(Cuenta::where('user_id', $beto->id)->sole()->saldo_actual)->toBe('40.00');

    $movimiento = Movimiento::where('documentable_id', $anticipo)->sole();
    expect($movimiento->only(['user_id', 'cuenta_id', 'monto', 'concepto', 'documentable_type']))
        ->toBe(['user_id' => $ana->id, 'cuenta_id' => $caja->id, 'monto' => '100.00', 'concepto' => 'Anticipo de Cotización COT-0012', 'documentable_type' => 'cotizacion_pago'])
        ->and($movimiento->fecha->toDateString())->toBe('2026-09-20')
        ->and(Movimiento::where('user_id', $beto->id)->sole()->concepto)->toBe('Pago total de Cotización COT-0003');
});
