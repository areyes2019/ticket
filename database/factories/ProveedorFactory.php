<?php

namespace Database\Factories;

use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use PhpCfdi\Rfc\RfcFaker;

/**
 * @extends Factory<Proveedor>
 */
class ProveedorFactory extends Factory
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
            'nombre_comercial' => fake()->company(),
            'nombre_contacto' => fake()->name(),
            'correo' => fake()->safeEmail(),
            'telefono' => '+52'.fake()->numerify('##########'),
            'rfc' => (new RfcFaker)->mexicanRfc(),
        ];
    }

    /**
     * Indicate that the supplier has active purchase orders.
     */
    public function conOrdenesActivas(): static
    {
        return $this->state(fn (array $attributes) => [
            'tiene_ordenes_activas' => true,
        ]);
    }
}
