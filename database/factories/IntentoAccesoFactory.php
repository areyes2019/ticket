<?php

namespace Database\Factories;

use App\Enums\ResultadoAcceso;
use App\Models\IntentoAcceso;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntentoAcceso>
 */
class IntentoAccesoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->safeEmail(),
            'user_id' => null,
            'resultado' => ResultadoAcceso::Fallido,
            'ip_address' => fake()->ipv4(),
            'navegador' => 'Chrome',
            'dispositivo' => 'Escritorio · Windows',
            'user_agent' => fake()->userAgent(),
        ];
    }
}
