<?php

use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    sembrarCatalogosSat();

    $this->catalogo = Catalogo::factory()->conDescuento(10)->create();
    $this->usuario = $this->catalogo->user;
});

// Sin la columna opcional de 028: un archivo de antes sigue siendo importable.
const ENCABEZADO_CSV = "nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor,utilidad_porcentaje\n";

const ENCABEZADO_CSV_COMPLETO = "nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor,utilidad_porcentaje,utilidad_distribuidor_porcentaje\n";

function csv(string $contenido, string $nombre = 'articulos.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($nombre, $contenido);
}

/**
 * Sube el archivo y sigue la redirección hasta la pantalla con el reporte.
 */
function importar(User $usuario, Catalogo $catalogo, UploadedFile $archivo)
{
    return test()->actingAs($usuario)
        ->followingRedirects()
        ->post('/articulos/importar', ['catalogo_id' => $catalogo->id, 'archivo' => $archivo]);
}

it('importa un archivo válido en el catálogo elegido', function () {
    $this->catalogo->update(['utilidad_porcentaje' => 20]);

    importar($this->usuario, $this->catalogo, csv(ENCABEZADO_CSV
        ."Sello redondo,R-45,44121604,H87,02,100.50,\n"
        ."Almohadilla,A-1,44121600,H87,02,35,50\n"))
        ->assertOk()
        ->assertSee('2 artículos importados.');

    expect(Articulo::where('catalogo_id', $this->catalogo->id)
        ->where('proveedor_id', $this->catalogo->proveedor_id)
        ->where('user_id', $this->usuario->id)
        ->count())->toBe(2)
        ->and(Articulo::firstWhere('modelo', 'R-45'))
        ->precio_proveedor->toBe('100.50')
        ->utilidad_porcentaje->toBeNull()
        ->costo_con_descuento->toBe('90.45')
        ->precio_unitario_sin_iva->toBe('108.62')
        ->and(Articulo::firstWhere('modelo', 'A-1'))
        ->utilidad_porcentaje->toBe('50.00')
        ->costo_con_descuento->toBe('31.50')
        ->precio_unitario_sin_iva->toBe('47.41');
});

it('rechaza la fila con una utilidad inválida', function () {
    importar($this->usuario, $this->catalogo, csv(ENCABEZADO_CSV."Sello redondo,R-45,44121604,H87,02,100,-5\n"))
        ->assertSee('1 fila rechazada.')
        ->assertSee('La utilidad debe estar entre 0 y 999.99%.');

    expect(Articulo::count())->toBe(0);
});

it('rechaza un archivo con el formato anterior de precio unitario', function () {
    $this->actingAs($this->usuario)
        ->post('/articulos/importar', [
            'catalogo_id' => $this->catalogo->id,
            'archivo' => csv("nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_unitario_sin_iva\nSello,R-45,44121604,H87,02,100\n"),
        ])
        ->assertSessionHasErrors(['archivo' => 'Al encabezado del archivo le faltan las columnas: precio_proveedor, utilidad_porcentaje.']);
});

it('importa las filas válidas y reporta fila, modelo y motivo de las demás', function () {
    importar($this->usuario, $this->catalogo, csv(ENCABEZADO_CSV
        ."Sello redondo,R-45,44121604,H87,02,100,\n"
        ."Sello malo,M-1,99999999,H87,02,100,\n"
        ."\n"
        ."Sin precio,M-2,44121604,H87,02,,\n"))
        ->assertSee('1 artículo importado.')
        ->assertSee('2 filas rechazadas.')
        ->assertSeeInOrder(['3', 'M-1', 'clave_prod_serv &quot;99999999&quot; no existe en el catálogo del SAT.'], false)
        ->assertSeeInOrder(['5', 'M-2', 'precio_proveedor'], false);

    expect(Articulo::count())->toBe(1);
});

it('detecta un nombre repetido dentro del mismo archivo', function () {
    importar($this->usuario, $this->catalogo, csv(ENCABEZADO_CSV
        ."Sello redondo,R-45,44121604,H87,02,100,\n"
        ."Sello redondo,R-46,44121604,H87,02,100,\n"))
        ->assertSee('1 artículo importado.')
        ->assertSee('Nombre duplicado');
});

it('rechaza el archivo completo si faltan columnas en el encabezado', function () {
    $this->actingAs($this->usuario)
        ->from('/articulos/importar')
        ->post('/articulos/importar', [
            'catalogo_id' => $this->catalogo->id,
            'archivo' => csv("nombre,modelo,precio_proveedor\nSello,R-45,100\n"),
        ])
        ->assertRedirect('/articulos/importar')
        ->assertSessionHasErrors(['archivo' => 'Al encabezado del archivo le faltan las columnas: clave_prod_serv, clave_unidad, objeto_imp, utilidad_porcentaje.']);

    expect(Articulo::count())->toBe(0);
});

it('acepta las columnas en cualquier orden y en mayúsculas', function () {
    importar($this->usuario, $this->catalogo, csv(
        "Utilidad_Porcentaje,PRECIO_PROVEEDOR,Modelo,Nombre,objeto_imp,clave_unidad,clave_prod_serv\n"
        .",100,R-45,Sello redondo,02,H87,44121604\n"
    ))->assertSee('1 artículo importado.');
});

it('rechaza un archivo vacío', function () {
    $this->actingAs($this->usuario)
        ->post('/articulos/importar', ['catalogo_id' => $this->catalogo->id, 'archivo' => csv("\n")])
        ->assertSessionHasErrors('archivo');
});

it('completa con cero el objeto de impuesto que una hoja de cálculo dejó en un dígito', function () {
    importar($this->usuario, $this->catalogo, csv(ENCABEZADO_CSV."Sello redondo,R-45,44121604,h87,2,100,\n"))
        ->assertSee('1 artículo importado.');

    expect(Articulo::sole())
        ->objeto_imp->value->toBe('02')
        ->clave_unidad->toBe('H87');
});

it('nombra la columna y el valor de un objeto de impuesto inválido', function () {
    importar($this->usuario, $this->catalogo, csv(ENCABEZADO_CSV."Sello redondo,R-45,44121604,H87,9,100,\n"))
        ->assertSee('objeto_imp &quot;09&quot; no es un valor válido (01, 02, 03, 04).', false);

    expect(Articulo::count())->toBe(0);
});

it('conserva acentos y símbolos de un archivo guardado en Windows-1252', function () {
    $contenido = mb_convert_encoding(ENCABEZADO_CSV."Sello redondo de Ø X 45 mm,Añil-1,44121604,H87,02,100,\n", 'Windows-1252', 'UTF-8');

    importar($this->usuario, $this->catalogo, csv($contenido))->assertSee('1 artículo importado.');

    expect(Articulo::sole())
        ->nombre->toBe('Sello redondo de Ø X 45 mm')
        ->modelo->toBe('Añil-1');
});

it('importa un archivo UTF-8 con BOM sin perder la primera columna', function () {
    importar($this->usuario, $this->catalogo, csv("\xEF\xBB\xBF".ENCABEZADO_CSV."Sello redondo de Ø X 45 mm,R-45,44121604,H87,02,100,\n"))
        ->assertSee('1 artículo importado.');

    expect(Articulo::sole()->nombre)->toBe('Sello redondo de Ø X 45 mm');
});

it('no vuelve a importar al recargar la pantalla del reporte', function () {
    $this->actingAs($this->usuario)
        ->post('/articulos/importar', [
            'catalogo_id' => $this->catalogo->id,
            'archivo' => csv(ENCABEZADO_CSV."Sello redondo,R-45,44121604,H87,02,100,\n"),
        ])
        ->assertRedirect(route('articulos.importar'));

    $this->get('/articulos/importar')->assertOk();
    $this->get('/articulos/importar')->assertDontSee('artículo importado');

    expect(Articulo::count())->toBe(1);
});

it('rechaza un catálogo ajeno, eliminado o de un proveedor eliminado', function () {
    $ajeno = Catalogo::factory()->create();
    $eliminado = Catalogo::factory()->for($this->catalogo->proveedor)->create();
    $eliminado->delete();
    $deProveedorEliminado = Catalogo::factory()->for(Proveedor::factory()->for($this->usuario))->create();
    $deProveedorEliminado->proveedor->delete();

    foreach ([$ajeno, $eliminado, $deProveedorEliminado] as $catalogo) {
        $this->actingAs($this->usuario)
            ->post('/articulos/importar', [
                'catalogo_id' => $catalogo->id,
                'archivo' => csv(ENCABEZADO_CSV."Sello redondo,R-45,44121604,H87,02,100,\n"),
            ])
            ->assertSessionHasErrors('catalogo_id');
    }

    expect(Articulo::count())->toBe(0);
});

it('exige un archivo CSV', function () {
    $this->actingAs($this->usuario)
        ->post('/articulos/importar', [
            'catalogo_id' => $this->catalogo->id,
            'archivo' => UploadedFile::fake()->image('foto.png'),
        ])
        ->assertSessionHasErrors('archivo');
});

describe('exportación', function () {
    beforeEach(function () {
        Articulo::factory()->for($this->catalogo)->create([
            'nombre' => 'Sello redondo de Ø X 45 mm', 'modelo' => 'R-45', 'clave_prod_serv' => '44121604',
            'clave_unidad' => 'H87', 'objeto_imp' => '02', 'precio_proveedor' => 100.5, 'utilidad_porcentaje' => 35,
        ]);
        Articulo::factory()->for($this->catalogo)->create([
            'nombre' => 'Almohadilla', 'modelo' => 'A-1', 'clave_prod_serv' => '44121600',
            'clave_unidad' => 'H87', 'objeto_imp' => '01', 'precio_proveedor' => 35,
        ]);
        Articulo::factory()->create(['nombre' => 'Artículo ajeno']);
    });

    it('exporta los artículos del usuario con las columnas de la importación', function () {
        $respuesta = $this->actingAs($this->usuario)->get('/articulos/exportar');

        $respuesta->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertDownload('articulos-'.now()->format('Y-m-d').'.csv');

        expect($respuesta->streamedContent())->toBe("\xEF\xBB\xBF".ENCABEZADO_CSV_COMPLETO
            ."Almohadilla,A-1,44121600,H87,01,35.00,,\n"
            ."\"Sello redondo de Ø X 45 mm\",R-45,44121604,H87,02,100.50,35.00,\n");
    });

    it('respeta los filtros y el orden del listado, sin paginar', function () {
        Articulo::factory()->count(30)->for($this->catalogo)->create(['nombre' => fn () => 'Sello '.fake()->unique()->word()]);

        $contenido = $this->actingAs($this->usuario)
            ->get('/articulos/exportar?nombre=sello&orden=costo&direccion=desc&por_pagina=10')
            ->streamedContent();
        $filas = array_slice(explode("\n", trim($contenido)), 1);

        expect($filas)->toHaveCount(31)
            ->and($contenido)->not->toContain('Almohadilla');

        // Todas en el mismo catálogo: el precio de lista sigue el orden del costo.
        $precios = array_map(fn (string $fila) => (float) str_getcsv($fila, ',', '"', '')[5], $filas);
        $ordenados = $precios;
        rsort($ordenados);

        expect($precios)->toBe($ordenados);
    });

    it('genera un archivo que se reimporta sin errores en otro proveedor', function () {
        $contenido = $this->actingAs($this->usuario)->get('/articulos/exportar')->streamedContent();
        $otro = Catalogo::factory()->for(Proveedor::factory()->for($this->usuario))->create();

        importar($this->usuario, $otro, csv($contenido))
            ->assertSee('2 artículos importados.')
            ->assertDontSee('rechazada');

        expect($otro->articulos()->pluck('nombre')->sort()->values()->all())
            ->toBe(['Almohadilla', 'Sello redondo de Ø X 45 mm'])
            ->and($otro->articulos()->pluck('utilidad_porcentaje', 'modelo')->sortKeys()->all())
            ->toBe(['A-1' => null, 'R-45' => '35.00']);
    });

    it('no pierde la utilidad distribuidor al exportar y reimportar', function () {
        Articulo::firstWhere('modelo', 'R-45')->update(['utilidad_distribuidor_porcentaje' => 12.5]);
        $contenido = $this->actingAs($this->usuario)->get('/articulos/exportar')->streamedContent();
        $otro = Catalogo::factory()->for(Proveedor::factory()->for($this->usuario))->create();

        expect($contenido)->toContain("02,100.50,35.00,12.50\n");

        importar($this->usuario, $otro, csv($contenido))->assertSee('2 artículos importados.');

        expect($otro->articulos()->pluck('utilidad_distribuidor_porcentaje', 'modelo')->sortKeys()->all())
            ->toBe(['A-1' => null, 'R-45' => '12.50']);
    });
});

describe('utilidad distribuidor (028)', function () {
    it('hereda la del catálogo con la celda vacía y guarda la propia con valor', function () {
        $this->catalogo->update(['utilidad_porcentaje' => 20, 'utilidad_distribuidor_porcentaje' => 10]);

        importar($this->usuario, $this->catalogo, csv(ENCABEZADO_CSV_COMPLETO
            ."Sello redondo,R-45,44121604,H87,02,100.50,,\n"
            ."Almohadilla,A-1,44121600,H87,02,35,50,5\n"))
            ->assertSee('2 artículos importados.');

        expect(Articulo::firstWhere('modelo', 'R-45'))
            ->utilidad_distribuidor_porcentaje->toBeNull()
            ->precio_distribuidor_sin_iva->toBe('100.00')
            ->and(Articulo::firstWhere('modelo', 'A-1'))
            ->utilidad_distribuidor_porcentaje->toBe('5.00')
            ->precio_distribuidor_sin_iva->toBe('33.62');
    });

    it('importa un archivo sin la columna como si viniera vacía', function () {
        $this->catalogo->update(['utilidad_distribuidor_porcentaje' => 10]);

        importar($this->usuario, $this->catalogo, csv(ENCABEZADO_CSV."Sello redondo,R-45,44121604,H87,02,100.50,\n"))
            ->assertSee('1 artículo importado.');

        expect(Articulo::firstWhere('modelo', 'R-45'))
            ->utilidad_distribuidor_porcentaje->toBeNull()
            ->precio_distribuidor_sin_iva->toBe('100.00');
    });

    it('rechaza la fila con una utilidad distribuidor fuera de rango', function () {
        importar($this->usuario, $this->catalogo, csv(ENCABEZADO_CSV_COMPLETO."Sello redondo,R-45,44121604,H87,02,100,,1000\n"))
            ->assertSee('1 fila rechazada.')
            ->assertSee('La utilidad distribuidor debe estar entre 0 y 999.99%.');
    });
});
