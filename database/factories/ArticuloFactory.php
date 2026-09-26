<?php

namespace Database\Factories;

use App\Enums\ObjetoImpuesto;
use App\Models\Articulo;
use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Las claves SAT deben existir en sat_claves_prod_serv y sat_claves_unidad
 * cuando la prueba valide contra el catálogo (ver sembrarCatalogosSat()).
 *
 * @extends Factory<Articulo>
 */
class ArticuloFactory extends Factory
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
            'nombre' => fake()->unique()->words(3, true),
            'modelo' => fake()->bothify('??-####'),
            'clave_prod_serv' => '44121600',
            'clave_unidad' => 'H87',
            'objeto_imp' => ObjetoImpuesto::SiObjeto,
            'precio_unitario_sin_iva' => fake()->randomFloat(2, 1, 5000),
        ];
    }
}
