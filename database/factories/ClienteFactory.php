<?php

namespace Database\Factories;

use App\Enums\RegimenFiscal;
use App\Models\Cliente;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use PhpCfdi\Rfc\RfcFaker;

/**
 * @extends Factory<Cliente>
 */
class ClienteFactory extends Factory
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
            'rfc' => (new RfcFaker)->mexicanRfc(),
            'razon_social' => mb_strtoupper(fake()->company()),
            'regimen_fiscal' => fake()->randomElement(RegimenFiscal::cases()),
            'codigo_postal_fiscal' => fake()->numerify('#####'),
            'nombre_comercial' => fake()->company(),
            'nombre_contacto' => fake()->name(),
            'correo' => fake()->safeEmail(),
            'telefono' => '+52'.fake()->numerify('##########'),
            'direccion_comercial' => fake()->address(),
            'descuento_permanente' => '0.00',
            'es_distribuidor' => false,
        ];
    }

    /**
     * Cliente distribuidor (028): el primer pago de su cotización no crea
     * venta (029).
     */
    public function distribuidor(): static
    {
        return $this->state(fn () => ['es_distribuidor' => true]);
    }
}
