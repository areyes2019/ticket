<?php

use App\Enums\ObjetoImpuesto;
use App\Models\Factura;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use App\Services\Facturacion\ConstructorPayloadFacturapi;

/**
 * Factura sin HTTP con las líneas dadas y sus totales calculados.
 *
 * @param  list<array<string, mixed>>  $lineas
 */
function facturaConLineas(array $lineas, ?string $globalTipo = null, ?string $globalValor = null): Factura
{
    $factura = Factura::factory()->create(['descuento_global_tipo' => $globalTipo, 'descuento_global_valor' => $globalValor]);
    $totales = CalculadoraTotalesDocumento::calcular($lineas, $globalTipo, $globalValor);

    foreach ($lineas as $i => $linea) {
        $factura->lineas()->create([
            'orden' => $i + 1,
            'articulo_id' => articuloFacturable($factura->user)->id,
            'cantidad' => $linea['cantidad'],
            'descripcion' => 'Sello '.($i + 1),
            'modelo' => 'M-'.($i + 1),
            'precio_unitario' => $linea['precio_unitario'],
            'descuento_tipo' => $linea['descuento_tipo'] ?? null,
            'descuento_valor' => $linea['descuento_valor'] ?? null,
            'tasa_iva' => $linea['tasa_iva'],
            'importe' => $totales['lineas'][$i]['importe'],
            'iva_importe' => $totales['lineas'][$i]['iva_importe'],
            'clave_prod_serv' => '44121604',
            'clave_unidad' => 'H87',
            'objeto_imp' => $linea['objeto_imp'] ?? ObjetoImpuesto::SiObjeto,
        ]);
    }

    $factura->aplicarTotales($totales);
    $factura->save();

    return $factura->load(['cliente', 'lineas']);
}

it('reparte el descuento global dentro del discount de cada ítem (caso de la remota: $580)', function () {
    $factura = facturaConLineas([
        ['cantidad' => 1, 'precio_unitario' => '700.00', 'tasa_iva' => '16'],
        ['cantidad' => 1, 'precio_unitario' => '300.00', 'tasa_iva' => '16'],
    ], 'monto', '500.00');

    $payload = app(ConstructorPayloadFacturapi::class)->factura($factura, Factura::datosReceptor($factura->cliente));

    expect($factura->total)->toBe('580.00')
        ->and($factura->total_iva_16)->toBe('80.00')
        ->and(array_column($payload['items'], 'discount'))->toBe([350.0, 150.0]);

    // Lo que facturapi.io calculará: Σ (precio × cantidad − discount) × 1.16.
    $base = array_sum(array_map(fn (array $item) => $item['product']['price'] * $item['quantity'] - $item['discount'], $payload['items']));
    expect(round($base * 1.16, 2))->toBe(580.0);
});

it('declara tax_included false e impuestos según tasa y objeto de impuesto', function () {
    $factura = facturaConLineas([
        ['cantidad' => 2, 'precio_unitario' => '100.00', 'tasa_iva' => '16', 'descuento_tipo' => 'porcentaje', 'descuento_valor' => '10'],
        ['cantidad' => 1, 'precio_unitario' => '50.00', 'tasa_iva' => '0'],
        ['cantidad' => 1, 'precio_unitario' => '40.00', 'tasa_iva' => 'exento'],
        ['cantidad' => 1, 'precio_unitario' => '30.00', 'tasa_iva' => 'exento', 'objeto_imp' => ObjetoImpuesto::NoObjeto],
    ]);

    $payload = app(ConstructorPayloadFacturapi::class)->factura($factura, Factura::datosReceptor($factura->cliente));
    $productos = array_column($payload['items'], 'product');

    expect(array_column($productos, 'tax_included'))->toBe([false, false, false, false])
        ->and(array_column($productos, 'taxability'))->toBe(['02', '02', '02', '01'])
        ->and(array_column($productos, 'taxes'))->toBe([
            [['type' => 'IVA', 'rate' => 0.16]],
            [['type' => 'IVA', 'rate' => 0]],
            [['type' => 'IVA', 'rate' => 0, 'factor' => 'Exento']],
            [],
        ])
        ->and($payload['items'][0]['discount'])->toBe(20.0)
        ->and($productos[0]['description'])->toBe('Sello 1 M-1')
        ->and($productos[0]['product_key'])->toBe('44121604')
        ->and($productos[0]['unit_key'])->toBe('H87')
        ->and($payload['use'])->toBe('G03')
        ->and($payload['payment_method'])->toBe('PUE')
        ->and($payload['customer'])->toHaveKeys(['legal_name', 'tax_id', 'tax_system', 'address'])
        ->and($payload)->not->toHaveKey('status')
        ->and($payload['external_id'])->toBe($factura->referenciaExterna())
        ->and($payload['idempotency_key'])->toBe($factura->referenciaExterna());
});

it('calcula la base de los impuestos del complemento en proporción al monto pagado', function () {
    $factura = facturaConLineas([
        ['cantidad' => 1, 'precio_unitario' => '100.00', 'tasa_iva' => '16'],
        ['cantidad' => 1, 'precio_unitario' => '100.00', 'tasa_iva' => '0'],
    ]);
    $factura->forceFill(['uuid_fiscal' => 'UUID-FACTURA'])->save();

    $complemento = $factura->complementoPago()->create(['fecha_pago' => '2026-09-01', 'monto' => '108.00', 'forma_pago' => '03']);
    $complemento->setRelation('factura', $factura);

    $payload = app(ConstructorPayloadFacturapi::class)->complementoPago($complemento);
    $documento = $payload['complements'][0]['data']['related_documents'][0];

    expect($payload['idempotency_key'])->toBe($complemento->referenciaExterna())
        ->and($payload['idempotency_key'])->toStartWith('complemento-');

    expect($factura->total)->toBe('216.00')
        ->and($documento['last_balance'])->toBe(216.0)
        ->and($documento['amount'])->toBe(108.0)
        ->and($documento['taxes'])->toBe([
            ['type' => 'IVA', 'rate' => 0.16, 'base' => 50.0],
            ['type' => 'IVA', 'rate' => 0, 'base' => 50.0],
        ]);
});
