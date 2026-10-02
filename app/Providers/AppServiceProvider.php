<?php

namespace App\Providers;

use App\Models\CotizacionPago;
use App\Models\OrdenCompra;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn () => Password::min(8)->mixedCase()->numbers()->symbols());

        Gate::define('ver-historial-accesos', fn (User $user) => $user->esAdministrador());

        Route::resourceVerbs(['create' => 'crear', 'edit' => 'editar']);

        // Alias estables en movimientos.documentable_type: mover o renombrar
        // un modelo no rompe los movimientos guardados.
        Relation::enforceMorphMap([
            'cotizacion_pago' => CotizacionPago::class,
            'orden_compra' => OrdenCompra::class,
        ]);
    }
}
