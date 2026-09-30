<?php

namespace App\Services\Facturacion;

use App\Enums\ObjetoImpuesto;
use App\Enums\TasaIva;
use App\Models\ComplementoPago;
use App\Models\Factura;
use App\Models\FacturaLinea;
use App\Services\Documentos\CalculadoraTotalesDocumento;

/**
 * Arma los cuerpos que se envían a facturapi.io, sin llamadas HTTP.
 *
 * El cliente y los artículos van inline en cada solicitud: no se
 * pre-registran en facturapi.io.
 */
class ConstructorPayloadFacturapi
{
    /**
     * @param  array{rfc: string, razon_social: string, regimen_fiscal: string, codigo_postal: string, correo: string|null}  $receptor
     * @return array<string, mixed>
     */
    public function factura(Factura $factura, array $receptor): array
    {
        return [
            'customer' => $this->cliente($receptor),
            'items' => $factura->lineas->map(fn (FacturaLinea $linea) => $this->concepto($linea))->values()->all(),
            'use' => $factura->uso_cfdi->value,
            'payment_form' => $factura->forma_pago->value,
            'payment_method' => $factura->metodo_pago->value,
            ...$this->referencia($factura->referenciaExterna()),
        ];
    }

    /**
     * idempotency_key: si un intento anterior sí timbró (p. ej. tras un
     * timeout), facturapi.io responde 409 en vez de timbrar otro CFDI. Un
     * intento rechazado no gasta la llave (verificado en el sandbox).
     * external_id: permite encontrar ese CFDI para adoptarlo.
     *
     * @return array{external_id: string, idempotency_key: string}
     */
    private function referencia(string $referencia): array
    {
        return ['external_id' => $referencia, 'idempotency_key' => $referencia];
    }

    /**
     * Payload real verificado contra facturapi.io (distinto al de su
     * documentación pública): complements[].data con related_documents.
     *
     * @return array<string, mixed>
     */
    public function complementoPago(ComplementoPago $complemento): array
    {
        $factura = $complemento->factura;

        return [
            'type' => 'P',
            'customer' => $this->cliente($factura->receptor()),
            'complements' => [[
                'type' => 'pago',
                'data' => [
                    'date' => $complemento->fecha_pago->toDateString(),
                    'payment_form' => $complemento->forma_pago->value,
                    'related_documents' => [[
                        'uuid' => $factura->uuid_fiscal,
                        'installment' => 1,
                        'last_balance' => (float) $factura->total,
                        'amount' => (float) $complemento->monto,
                        'taxes' => $this->impuestosPagados($factura, (string) $complemento->monto),
                    ]],
                ],
            ]],
            ...$this->referencia($complemento->referenciaExterna()),
        ];
    }

    /**
     * @param  array{rfc: string, razon_social: string, regimen_fiscal: string, codigo_postal: string, correo: string|null}  $receptor
     * @return array<string, mixed>
     */
    private function cliente(array $receptor): array
    {
        return array_filter([
            'legal_name' => $receptor['razon_social'],
            'tax_id' => $receptor['rfc'],
            'tax_system' => $receptor['regimen_fiscal'],
            'email' => $receptor['correo'],
            'address' => ['zip' => $receptor['codigo_postal']],
        ], fn ($valor) => $valor !== null && $valor !== '');
    }

    /**
     * tax_included: false siempre. Sin él facturapi.io trata el precio como si
     * ya incluyera el IVA y lo extrae en vez de sumarlo (price 700, discount
     * 350 timbró $350.01 en lugar de $406.00).
     *
     * discount lleva el descuento de la línea más su parte del descuento
     * global, para que la base del IVA sea la misma que calculó el sistema.
     *
     * @return array<string, mixed>
     */
    private function concepto(FacturaLinea $linea): array
    {
        return [
            'quantity' => $linea->cantidad,
            'discount' => (float) $linea->descuentoCfdi(),
            'product' => [
                'description' => trim($linea->descripcion.' '.$linea->modelo),
                'product_key' => $linea->clave_prod_serv,
                'unit_key' => $linea->clave_unidad,
                'price' => (float) $linea->precio_unitario,
                'tax_included' => false,
                'taxability' => $linea->objeto_imp->value,
                'taxes' => $linea->objeto_imp === ObjetoImpuesto::SiObjeto ? [$this->impuesto($linea->tasa_iva)] : [],
            ],
        ];
    }

    /**
     * facturapi.io exige rate también en exento ("items.N.product.taxes.0.rate
     * es requerido", visto en el sandbox).
     *
     * @return array<string, mixed>
     */
    private function impuesto(TasaIva $tasa): array
    {
        return match ($tasa) {
            TasaIva::Dieciseis => ['type' => 'IVA', 'rate' => 0.16],
            TasaIva::Cero => ['type' => 'IVA', 'rate' => 0],
            TasaIva::Exento => ['type' => 'IVA', 'rate' => 0, 'factor' => 'Exento'],
        };
    }

    /**
     * Un impuesto por tasa presente en la factura, con base proporcional a lo
     * pagado: base de la tasa × monto / total.
     *
     * @return list<array<string, mixed>>
     */
    private function impuestosPagados(Factura $factura, string $monto): array
    {
        $total = CalculadoraTotalesDocumento::centavos($factura->total);
        $pagado = CalculadoraTotalesDocumento::centavos($monto);
        $impuestos = [];

        $bases = [
            TasaIva::Dieciseis->value => $factura->base_iva_16,
            TasaIva::Cero->value => $factura->base_iva_0,
            TasaIva::Exento->value => $factura->base_exento,
        ];

        foreach ($bases as $tasa => $base) {
            $centavos = CalculadoraTotalesDocumento::centavos($base);

            if ($centavos === 0 || $total === 0) {
                continue;
            }

            $proporcional = $pagado === $total ? $centavos : (int) round($centavos * $pagado / $total);

            $impuestos[] = [
                ...$this->impuesto(TasaIva::from($tasa)),
                'base' => (float) CalculadoraTotalesDocumento::pesos($proporcional),
            ];
        }

        return $impuestos;
    }
}
