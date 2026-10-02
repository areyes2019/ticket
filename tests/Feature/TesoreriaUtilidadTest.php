<?php

use App\Enums\EstadoCotizacion;
use App\Enums\TipoMovimiento;
use App\Enums\TipoPago;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\Movimiento;
use App\Models\User;
use App\Services\Tesoreria\RegistradorMovimientos;
use Illuminate\Support\Facades\DB;

/**
 * Cotización enviada con líneas ya calculadas. Cada línea es
 * [cantidad, importe neto, costo_unitario|null, ¿de catálogo?].
 *
 * @param  list<array{0: int, 1: string, 2: string|null, 3: bool}>  $lineas
 */
function cotizacionConCostos(User $user, array $lineas): Cotizacion
{
    $cotizacion = Cotizacion::factory()
        ->for(Cliente::factory()->for($user))
        ->enEstado(EstadoCotizacion::Enviada)
        ->create(['user_id' => $user->id]);
    $articulo = articuloFacturable($user);

    foreach ($lineas as $i => [$cantidad, $importe, $costo, $deCatalogo]) {
        $cotizacion->lineas()->create([
            'orden' => $i + 1,
            'articulo_id' => $deCatalogo ? $articulo->id : null,
            'cantidad' => $cantidad,
            'descripcion' => "Línea {$i}",
            'precio_unitario' => $importe,
            'tasa_iva' => '16',
            'importe' => $importe,
            'iva_importe' => '0.00',
            'costo_unitario' => $costo,
        ]);
    }

    $cotizacion->forceFill(['total' => collect($lineas)->sum(fn ($l) => (float) $l[1])])->saveQuietly();

    return $cotizacion->fresh();
}

/**
 * Registra un pago por la ruta normal (crea su ingreso).
 */
function pagarCotizacion(User $user, Cotizacion $cotizacion, Cuenta $cuenta, string $tipo, ?string $monto = null): void
{
    test()->actingAs($user)->post("/cotizaciones/{$cotizacion->id}/pagos", array_filter([
        'tipo' => $tipo,
        'fecha_pago' => today('America/Mexico_City')->toDateString(),
        'cuenta_id' => $cuenta->id,
        'monto' => $monto,
    ]))->assertSessionHasNoErrors();
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->cuenta = Cuenta::factory()->for($this->user)->create();
});

it('calcula la utilidad con el importe neto menos el costo capturado', function () {
    // 3 × (200 − 120) + 1 × (450 − 300) = 390
    $cotizacion = cotizacionConCostos($this->user, [[3, '600.00', '120.00', true], [1, '450.00', '300.00', true]]);

    expect($cotizacion->utilidadVenta())->toBe(['utilidad' => '390.00', 'parcial' => false]);
});

it('puede ser negativa si se vendió bajo costo', function () {
    $cotizacion = cotizacionConCostos($this->user, [[2, '100.00', '80.00', true]]);

    expect($cotizacion->utilidadVenta())->toBe(['utilidad' => '-60.00', 'parcial' => false]);
});

it('marca parcial con líneas libres o de catálogo sin costo', function (array $extra) {
    $cotizacion = cotizacionConCostos($this->user, [[1, '500.00', '200.00', true], $extra]);

    expect($cotizacion->utilidadVenta())->toBe(['utilidad' => '300.00', 'parcial' => true]);
})->with([
    'línea libre' => [[1, '999.00', null, false]],
    'artículo sin costo' => [[1, '999.00', null, true]],
]);

it('sin ninguna línea con costo no está disponible', function () {
    $cotizacion = cotizacionConCostos($this->user, [[1, '500.00', null, false]]);

    expect($cotizacion->utilidadVenta())->toBe(['utilidad' => null, 'parcial' => false]);
});

it('los movimientos de una cotización pagada en partes muestran la misma utilidad completa', function () {
    $cotizacion = cotizacionConCostos($this->user, [[3, '600.00', '120.00', true], [1, '400.00', null, false]]);

    pagarCotizacion($this->user, $cotizacion, $this->cuenta, TipoPago::Anticipo->value, '100.00');
    pagarCotizacion($this->user, $cotizacion, $this->cuenta, TipoPago::Saldo->value);

    $origenes = Movimiento::orderBy('id')->get()->map->documentoOrigen();

    expect($origenes)->toHaveCount(2)
        ->and($origenes->pluck('utilidad')->all())->toBe(['240.00', '240.00'])
        ->and($origenes->pluck('utilidad_parcial')->all())->toBe([true, true])
        ->and($origenes[0]['etiqueta'])->toBe($cotizacion->folio_formateado);
});

it('un movimiento manual no tiene documento ni utilidad', function () {
    $movimiento = app(RegistradorMovimientos::class)->registrar($this->cuenta, TipoMovimiento::Ingreso, '10', today()->toDateString(), 'Ventas varias');

    expect($movimiento->documentoOrigen())->toBeNull();
});

it('el listado pinta la utilidad, parcial y no disponible', function () {
    $completa = cotizacionConCostos($this->user, [[1, '1000.00', '400.00', true]]);
    $parcial = cotizacionConCostos($this->user, [[1, '1000.00', '700.00', true], [1, '50.00', null, false]]);
    $sinCosto = cotizacionConCostos($this->user, [[1, '80.00', null, false]]);

    foreach ([$completa, $parcial, $sinCosto] as $cotizacion) {
        pagarCotizacion($this->user, $cotizacion, $this->cuenta, TipoPago::PagoTotal->value);
    }

    $this->actingAs($this->user)->get('/tesoreria/movimientos')
        ->assertSee('$600.00')
        ->assertSee('$300.00')
        ->assertSee('Parcial')
        ->assertSee('No disponible');
});

it('el listado no hace una consulta por fila para la utilidad', function () {
    $contar = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user)->get('/tesoreria/movimientos')->assertOk();

        return count(DB::getQueryLog());
    };

    $cotizacion = cotizacionConCostos($this->user, [[1, '100.00', '50.00', true]]);
    pagarCotizacion($this->user, $cotizacion, $this->cuenta, TipoPago::PagoTotal->value);
    $conUna = $contar();

    foreach (range(1, 4) as $i) {
        $cotizacion = cotizacionConCostos($this->user, [[1, '100.00', '50.00', true]]);
        pagarCotizacion($this->user, $cotizacion, $this->cuenta, TipoPago::PagoTotal->value);
    }

    expect($contar())->toBe($conUna);
});
