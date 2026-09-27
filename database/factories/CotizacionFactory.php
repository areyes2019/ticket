<?php

namespace Database\Factories;

use App\Enums\EstadoCotizacion;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\User;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Sin líneas; conLinea() agrega una y calcula los totales. El folio avanza el
 * contador del usuario, igual que el alta real.
 *
 * @extends Factory<Cotizacion>
 */
class CotizacionFactory extends Factory
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
                $user->increment('ultimo_folio_cotizacion');

                return $user->ultimo_folio_cotizacion;
            },
            'estado' => EstadoCotizacion::Borrador,
        ];
    }

    public function enEstado(EstadoCotizacion $estado): static
    {
        return $this->state(fn () => ['estado' => $estado]);
    }

    /**
     * Una línea libre (sin artículo) de cantidad × precio al 16%.
     */
    public function conLinea(int $cantidad = 1, string $precio = '100.00'): static
    {
        return $this->afterCreating(function (Cotizacion $cotizacion) use ($cantidad, $precio) {
            $linea = ['cantidad' => $cantidad, 'precio_unitario' => $precio, 'tasa_iva' => '16', 'descuento_tipo' => null, 'descuento_valor' => null];
            $totales = CalculadoraTotalesDocumento::calcular([$linea]);

            $cotizacion->lineas()->create([
                ...$linea,
                'orden' => 1,
                'descripcion' => 'Sello de prueba',
                'modelo' => 'P-1',
                'importe' => $totales['lineas'][0]['importe'],
                'iva_importe' => $totales['lineas'][0]['iva_importe'],
            ]);

            $cotizacion->aplicarTotales($totales);
            $cotizacion->saveQuietly();
        });
    }
}
