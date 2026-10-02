<?php

namespace Database\Factories;

use App\Enums\EstadoPedido;
use App\Models\Pedido;
use App\Models\User;
use App\Services\Documentos\CalculadoraTotalesDocumento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Sin líneas ni pagos; conLinea() agrega una línea libre y calcula los
 * totales. El folio avanza el contador del usuario, igual que el alta real.
 *
 * @extends Factory<Pedido>
 */
class PedidoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'folio' => function (array $atributos) {
                $user = User::findOrFail($atributos['user_id']);
                $user->increment('ultimo_folio_pedido');

                return $user->ultimo_folio_pedido;
            },
            'estado' => EstadoPedido::Pendiente,
            'cliente_nombre' => fake()->name(),
            'cliente_telefono' => '+52'.fake()->numerify('449#######'),
            'cliente_correo' => fake()->safeEmail(),
        ];
    }

    public function enEstado(EstadoPedido $estado): static
    {
        return $this->state(fn () => ['estado' => $estado]);
    }

    /**
     * Una línea libre (sin artículo) de cantidad × precio al 16%.
     */
    public function conLinea(int $cantidad = 1, string $precio = '100.00'): static
    {
        return $this->afterCreating(function (Pedido $pedido) use ($cantidad, $precio) {
            $linea = ['cantidad' => $cantidad, 'precio_unitario' => $precio, 'tasa_iva' => '16', 'descuento_tipo' => null, 'descuento_valor' => null];
            $totales = CalculadoraTotalesDocumento::calcular([$linea]);

            $pedido->lineas()->create([
                ...$linea,
                'orden' => 1,
                'descripcion' => 'Sello de prueba',
                'modelo' => 'P-1',
                'importe' => $totales['lineas'][0]['importe'],
                'iva_importe' => $totales['lineas'][0]['iva_importe'],
            ]);

            $pedido->aplicarTotales($totales);
            $pedido->saveQuietly();
        });
    }
}
