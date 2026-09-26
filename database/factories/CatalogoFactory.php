<?php

namespace Database\Factories;

use App\Models\Catalogo;
use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Catalogo>
 */
class CatalogoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proveedor_id' => Proveedor::factory(),
            'user_id' => fn (array $atributos) => Proveedor::withTrashed()->findOrFail($atributos['proveedor_id'])->user_id,
            'nombre' => fake()->unique()->words(2, true),
            'descuento' => 0,
        ];
    }

    /**
     * Indicate the catalog's discount percentage.
     */
    public function conDescuento(float $descuento): static
    {
        return $this->state(fn (array $attributes) => [
            'descuento' => $descuento,
        ]);
    }
}
