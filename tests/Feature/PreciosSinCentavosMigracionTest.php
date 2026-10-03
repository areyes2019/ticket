<?php

use App\Enums\ObjetoImpuesto;
use App\Models\Articulo;
use App\Models\Catalogo;
use Illuminate\Support\Facades\DB;

it('deja todos los artículos en un peso entero y se puede repetir', function () {
    $migracion = require database_path('migrations/2026_10_09_100000_precios_sin_centavos.php');
    $catalogo = Catalogo::factory()->conUtilidad(55)->create();
    $hereda = Articulo::factory()->for($catalogo)->create(['precio_proveedor' => '130.00']);
    $propia = Articulo::factory()->for($catalogo)->create(['precio_proveedor' => '6.00', 'utilidad_porcentaje' => 0]);
    $sinIva = Articulo::factory()->for($catalogo)->create(['precio_proveedor' => '130.00', 'objeto_imp' => ObjetoImpuesto::NoObjeto]);
    $eliminado = Articulo::factory()->for($catalogo)->create(['precio_proveedor' => '130.00']);
    $eliminado->delete();

    // Los precios como quedaron antes de esta historia: el markup sin redondeo.
    $migracion->down();
    $antes = Articulo::withTrashed()->orderBy('id')->get(['id', 'precio_proveedor', 'utilidad_porcentaje', 'costo_con_descuento', 'precio_unitario_sin_iva']);

    expect($antes->pluck('precio_unitario_sin_iva')->all())->toBe(['201.50', '6.00', '201.50', '201.50']);

    $migracion->up();
    $despues = Articulo::withTrashed()->orderBy('id')->get();

    expect($despues->pluck('precio_unitario_sin_iva')->all())->toBe(['201.72', '6.90', '202.00', '201.72'])
        ->and($despues->every(fn (Articulo $articulo) => $articulo->precio_unitario_con_iva === floor($articulo->precio_unitario_con_iva)))->toBeTrue()
        ->and($despues->pluck('precio_proveedor')->all())->toBe($antes->pluck('precio_proveedor')->all())
        ->and($despues->pluck('utilidad_porcentaje')->all())->toBe($antes->pluck('utilidad_porcentaje')->all())
        ->and($despues->pluck('costo_con_descuento')->all())->toBe($antes->pluck('costo_con_descuento')->all());

    $migracion->up();

    expect(DB::table('articulos')->orderBy('id')->pluck('precio_unitario_sin_iva')->map(fn ($precio) => number_format((float) $precio, 2, '.', ''))->all())
        ->toBe(['201.72', '6.90', '202.00', '201.72']);
});
