<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('registro', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('registro', [RegisteredUserController::class, 'store']);

    Route::get('olvide-contrasena', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('olvide-contrasena', [PasswordResetLinkController::class, 'store'])->name('password.email');

    Route::get('restablecer-contrasena/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('restablecer-contrasena', [NewPasswordController::class, 'store'])->name('password.store');
});

Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
