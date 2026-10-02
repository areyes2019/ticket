<?php

namespace App\Providers;

use App\Models\Cotizacion;
use App\Models\CotizacionPago;
use App\Models\Factura;
use App\Models\OrdenCompra;
use App\Models\Pedido;
use App\Models\PedidoPago;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        // Alias estables en documentable_type (movimientos de Tesorería y de
        // inventario): mover o renombrar un modelo no rompe lo guardado.
        Relation::enforceMorphMap([
            'cotizacion_pago' => CotizacionPago::class,
            'orden_compra' => OrdenCompra::class,
            'factura' => Factura::class,
            'cotizacion' => Cotizacion::class,
            'pedido' => Pedido::class,
            'pedido_pago' => PedidoPago::class,
        ]);

        // Portal público de autofacturación (019): lo puede llamar cualquiera.
        RateLimiter::for('autofactura', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
    }
}
