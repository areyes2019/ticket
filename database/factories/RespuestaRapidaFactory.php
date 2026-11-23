<?php

namespace Database\Factories;

use App\Models\RespuestaRapida;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RespuestaRapida>
 */
class RespuestaRapidaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'atajo' => fake()->unique()->lexify('????????'),
            'texto' => fake()->sentence(),
        ];
    }
}
