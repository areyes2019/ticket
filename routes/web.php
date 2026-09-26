<?php

use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ConstanciaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EstilosController;
use App\Http\Controllers\HistorialAccesoController;
use App\Http\Controllers\InicioController;
use App\Http\Controllers\ProveedorController;
use App\Http\Middleware\AsegurarUsuarioActivo;
use Illuminate\Support\Facades\Route;

Route::get('/', [InicioController::class, 'index'])->name('inicio');
Route::get('/estado', [InicioController::class, 'estado'])->name('estado');
Route::post('/eco', [InicioController::class, 'eco'])->name('eco');
Route::get('/estilos', EstilosController::class)->name('estilos');

Route::middleware(['auth', AsegurarUsuarioActivo::class])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/historial-accesos', [HistorialAccesoController::class, 'index'])
        ->middleware('can:ver-historial-accesos')
        ->name('historial-accesos.index');

    Route::get('clientes/buscar', [ClienteController::class, 'buscar'])->name('clientes.buscar');

    Route::post('clientes/constancia', ConstanciaController::class)
        ->middleware('throttle:10,1')
        ->name('clientes.constancia');

    Route::resource('clientes', ClienteController::class)
        ->except('show')
        ->parameters(['clientes' => 'cliente']);

    Route::resource('proveedores', ProveedorController::class)
        ->except('show')
        ->parameters(['proveedores' => 'proveedor']);
});

require __DIR__.'/auth.php';
