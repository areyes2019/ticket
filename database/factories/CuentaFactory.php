<?php

namespace Database\Factories;

use App\Enums\TipoCuenta;
use App\Models\Cuenta;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cuenta>
 */
class CuentaFactory extends Factory
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
            'nombre' => fake()->unique()->words(2, true),
            'tipo' => TipoCuenta::Banco,
            'saldo_inicial' => '0.00',
            'saldo_actual' => fn (array $atributos) => $atributos['saldo_inicial'],
            'activa' => true,
        ];
    }

    /**
     * Cuenta que arranca con un saldo (inicial y actual).
     */
    public function conSaldo(string $saldo): static
    {
        return $this->state(fn () => ['saldo_inicial' => $saldo, 'saldo_actual' => $saldo]);
    }

    public function inactiva(): static
    {
        return $this->state(fn () => ['activa' => false]);
    }
}
