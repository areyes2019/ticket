<?php

namespace Database\Factories;

use App\Enums\EstadoFactura;
use App\Enums\FormaPago;
use App\Enums\MetodoPago;
use App\Enums\UsoCfdi;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Cliente;
use App\Models\Factura;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Sin líneas; conLinea() agrega una (con su artículo) y calcula los totales.
 * El folio avanza el contador del usuario, igual que el alta real.
 *
 * @extends Factory<Factura>
 */
class FacturaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cliente_id' => Cliente::factory(),
            'user_id' => fn (array $atributos) => Cliente::withTrashed()->findOrFail($atributos['cliente_id'])->user_id,
            'folio' => function (array $atributos) {
                $user = User::findOrFail($atributos['user_id']);
                $user->increment('ultimo_folio_factura');

                return $user->ultimo_folio_factura;
            },
            'estado' => EstadoFactura::Borrador,
            'uso_cfdi' => UsoCfdi::GastosEnGeneral,
            'forma_pago' => FormaPago::Transferencia,
            'metodo_pago' => MetodoPago::UnaExhibicion,
        ];
    }

    /**
     * Pago diferido: forma 99, como exige el SAT.
     */
    public function ppd(): static
    {
        return $this->state(fn () => ['metodo_pago' => MetodoPago::Diferido, 'forma_pago' => FormaPago::PorDefinir]);
    }

    /**
     * Timbrada con sellos de prueba y copia fiscal del receptor tomada del
     * cliente.
     */
    public function timbrada(): static
    {
        return $this->state(fn () => [
            'estado' => EstadoFactura::Timbrada,
            'facturapi_invoice_id' => Str::random(24),
            'uuid_fiscal' => (string) Str::uuid(),
            'facturapi_serie' => 'A',
            'facturapi_folio' => fake()->unique()->numberBetween(1, 99999),
            'sello_cfdi' => str_repeat('SelloCfdi', 20),
            'sello_sat' => str_repeat('SelloSat', 20),
            'no_certificado_sat' => '00001000000504465028',
            'fecha_timbrado' => now(),
            'cadena_original_sat' => '||1.1|uuid|fecha|sello|00001000000504465028||',
            'version_comprobante' => '4.0',
            'emisor_rfc' => 'EKU9003173C9',
            'emisor_razon_social' => 'ESCUELA KEMPER URGATE',
            'emisor_regimen_fiscal' => '601',
            'lugar_expedicion' => '26015',
        ])->afterCreating(function (Factura $factura) {
            $factura->forceFill([
                'receptor_rfc' => $factura->cliente->rfc,
                'receptor_razon_social' => $factura->cliente->razon_social,
                'receptor_regimen_fiscal' => $factura->cliente->regimen_fiscal->value,
                'receptor_codigo_postal' => $factura->cliente->codigo_postal_fiscal,
                'receptor_correo' => $factura->cliente->correo,
            ])->saveQuietly();
        });
    }

    public function enEstado(EstadoFactura $estado): static
    {
        return $this->state(fn () => ['estado' => $estado]);
    }

    /**
     * Una línea de cantidad × precio al 16%, con un artículo del mismo usuario.
     */
    public function conLinea(int $cantidad = 1, string $precio = '100.00'): static
    {
        return $this->afterCreating(function (Factura $factura) use ($cantidad, $precio) {
            $articulo = Articulo::factory()
                ->for(Catalogo::factory()->for(Proveedor::factory()->for($factura->user))->create(['user_id' => $factura->user_id]))
                ->create(['user_id' => $factura->user_id, 'nombre' => 'Sello de prueba', 'modelo' => 'P-1']);

            $linea = ['cantidad' => $cantidad, 'precio_unitario' => $precio, 'tasa_iva' => '16', 'descuento_tipo' => null, 'descuento_valor' => null];
            $totales = CalculadoraTotalesDocumento::calcular([$linea]);

            $factura->lineas()->create([
                ...$linea,
                'orden' => 1,
                'articulo_id' => $articulo->id,
                'descripcion' => $articulo->nombre,
                'modelo' => $articulo->modelo,
                'importe' => $totales['lineas'][0]['importe'],
                'iva_importe' => $totales['lineas'][0]['iva_importe'],
                'clave_prod_serv' => $articulo->clave_prod_serv,
                'clave_unidad' => $articulo->clave_unidad,
                'objeto_imp' => $articulo->objeto_imp,
            ]);

            $factura->aplicarTotales($totales);
            $factura->saveQuietly();
        });
    }
}
