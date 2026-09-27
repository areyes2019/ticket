<?php

namespace Database\Factories;

use App\Enums\ObjetoImpuesto;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Services\Articulos\ProcesadorImagenArticulo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\UploadedFile;

/**
 * Las claves SAT deben existir en sat_claves_prod_serv y sat_claves_unidad
 * cuando la prueba valide contra el catálogo (ver sembrarCatalogosSat()).
 *
 * proveedor_id, costo_con_descuento y precio_unitario_sin_iva los calcula
 * el modelo a partir del catálogo, el precio de lista y la utilidad.
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
            'catalogo_id' => Catalogo::factory(),
            'user_id' => fn (array $atributos) => Catalogo::withTrashed()->findOrFail($atributos['catalogo_id'])->user_id,
            'nombre' => fake()->unique()->words(3, true),
            'modelo' => fake()->bothify('??-####'),
            'clave_prod_serv' => '44121600',
            'clave_unidad' => 'H87',
            'objeto_imp' => ObjetoImpuesto::SiObjeto,
            'precio_proveedor' => fake()->randomFloat(2, 1, 5000),
            'utilidad_porcentaje' => null,
        ];
    }

    /**
     * Con una imagen de 100×100 guardada en el disco privado (usar con
     * Storage::fake('local')).
     */
    public function conImagen(): static
    {
        return $this->afterCreating(fn (Articulo $articulo) => app(ProcesadorImagenArticulo::class)
            ->guardar($articulo, UploadedFile::fake()->image('foto.png', 100, 100)->getContent()));
    }
}
