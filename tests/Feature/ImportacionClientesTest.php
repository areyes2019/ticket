<?php

use App\Enums\RegimenFiscal;
use App\Models\Cliente;
use App\Models\User;
use Illuminate\Http\UploadedFile;

const ENCABEZADO_CLIENTES = "RazonSocial,RFC,Email,Calle,NumExterior,NumInterior,Colonia,CP,Ciudad,Municipio,Estado,Pais,RegimenFiscal\n";

function csvClientes(string $contenido): UploadedFile
{
    return UploadedFile::fake()->createWithContent('clientes.csv', $contenido);
}

/**
 * Sube el archivo y sigue la redirección hasta la pantalla con el reporte.
 */
function importarClientes(User $usuario, UploadedFile $archivo)
{
    return test()->actingAs($usuario)
        ->followingRedirects()
        ->post('/clientes/importar', ['archivo' => $archivo]);
}

beforeEach(function () {
    $this->usuario = User::factory()->create();
});

it('muestra la pantalla de importación', function () {
    $this->actingAs($this->usuario)
        ->get('/clientes/importar')
        ->assertOk()
        ->assertSee('Importar clientes');
});

it('importa el formato del sistema anterior y arma la dirección', function () {
    importarClientes($this->usuario, csvClientes(ENCABEZADO_CLIENTES
        ."RECO DE REYNOSA,RRE9704025U0,mvallesm@gmail.com,Rio Guayalejo,1308,1-E,Longoria,88660,Reynosa,Reynosa,TAMAULIPAS,MEXICO,601\n"))
        ->assertOk()
        ->assertSee('1 cliente importado.');

    expect(Cliente::where('user_id', $this->usuario->id)->sole())
        ->razon_social->toBe('RECO DE REYNOSA')
        ->rfc->toBe('RRE9704025U0')
        ->correo->toBe('mvallesm@gmail.com')
        ->codigo_postal_fiscal->toBe('88660')
        ->regimen_fiscal->toBe(RegimenFiscal::GeneralPersonasMorales)
        ->direccion_comercial->toBe('Rio Guayalejo 1308 Int. 1-E, Col. Longoria, Reynosa, TAMAULIPAS')
        ->descuento_permanente->toBe('0.00');
});

it('repara lo que Excel reescribe al guardar', function () {
    importarClientes($this->usuario, csvClientes("razon social;rfc;cp;régimen fiscal;email\n"
        ."CLIENTE CENTRO;cacx7605101p8;1000;612 - Actividades Empresariales;uno@ejemplo.com; dos@ejemplo.com\n"))
        ->assertSee('1 cliente importado.');

    expect(Cliente::sole())
        ->rfc->toBe('CACX7605101P8')
        ->codigo_postal_fiscal->toBe('01000')
        ->regimen_fiscal->toBe(RegimenFiscal::ActividadesEmpresarialesProfesionales)
        ->correo->toBe('uno@ejemplo.com')
        ->direccion_comercial->toBeNull();
});

it('rechaza filas inválidas y duplicadas sin detener el archivo', function () {
    Cliente::factory()->for($this->usuario)->create(['rfc' => 'CACX7605101P8']);

    importarClientes($this->usuario, csvClientes(ENCABEZADO_CLIENTES
        ."RECO DE REYNOSA,RRE9704025U0,,,,,,88660,,,,,601\n"
        ."REPETIDO EN ARCHIVO,RRE9704025U0,,,,,,88660,,,,,601\n"
        ."YA REGISTRADO,CACX7605101P8,,,,,,88660,,,,,612\n"
        ."RFC MALO,XYZ,,,,,,88660,,,,,601\n"
        ."REGIMEN MALO,GODE561231GR8,,,,,,88660,,,,,999\n"))
        ->assertSee('1 cliente importado.')
        ->assertSee('4 filas rechazadas.')
        ->assertSee('RFC duplicado')
        ->assertSee('REGIMEN MALO');

    expect(Cliente::where('user_id', $this->usuario->id)->count())->toBe(2);
});

it('no compara contra los clientes de otro usuario', function () {
    Cliente::factory()->create(['rfc' => 'RRE9704025U0']);

    importarClientes($this->usuario, csvClientes(ENCABEZADO_CLIENTES."RECO DE REYNOSA,RRE9704025U0,,,,,,88660,,,,,601\n"))
        ->assertSee('1 cliente importado.');
});

it('rechaza un archivo al que le faltan columnas obligatorias', function () {
    importarClientes($this->usuario, csvClientes("RazonSocial,RFC\nRECO DE REYNOSA,RRE9704025U0\n"))
        ->assertSee('Al encabezado del archivo le faltan las columnas: RegimenFiscal, CP.');

    expect(Cliente::count())->toBe(0);
});

it('acepta archivos guardados en Windows-1252', function () {
    importarClientes($this->usuario, csvClientes(mb_convert_encoding(ENCABEZADO_CLIENTES
        ."PAPELERÍA MÉXICO,RRE9704025U0,,,,,,88660,,,,,601\n", 'Windows-1252', 'UTF-8')))
        ->assertSee('1 cliente importado.');

    expect(Cliente::sole()->razon_social)->toBe('PAPELERÍA MÉXICO');
});
