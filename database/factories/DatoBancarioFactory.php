<?php

namespace Database\Factories;

use App\Models\DatoBancario;
use App\Rules\ClabeValida;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatoBancario>
 */
class DatoBancarioFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $diecisiete = fake()->numerify('#################');

        return [
            'nombre_banco' => fake()->randomElement(['BBVA', 'Santander', 'Banorte', 'HSBC']),
            'beneficiario' => fake()->name(),
            'numero_cuenta' => fake()->numerify('##########'),
            'tarjeta' => null,
            'clabe' => $diecisiete.ClabeValida::digitoVerificador($diecisiete),
            'visible_en_cotizaciones' => true,
        ];
    }

    public function oculto(): static
    {
        return $this->state(fn () => ['visible_en_cotizaciones' => false]);
    }
}
