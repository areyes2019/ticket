<?php

use App\Http\Controllers\ArticuloController;
use App\Http\Controllers\AutofacturaController;
use App\Http\Controllers\CatalogoController;
use App\Http\Controllers\CatalogoSatController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ComplementoPagoController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\ConstanciaController;
use App\Http\Controllers\CotizacionAceptacionController;
use App\Http\Controllers\CotizacionController;
use App\Http\Controllers\CotizacionPagoController;
use App\Http\Controllers\CuentaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnvioCotizacionController;
use App\Http\Controllers\EnvioFacturaController;
use App\Http\Controllers\EnvioOrdenCompraController;
use App\Http\Controllers\EstilosController;
use App\Http\Controllers\ExistenciaController;
use App\Http\Controllers\ExportacionArticulosController;
use App\Http\Controllers\FacturaController;
use App\Http\Controllers\GenerarOrdenesCompraController;
use App\Http\Controllers\HistorialAccesoController;
use App\Http\Controllers\ImagenesArticulosController;
use App\Http\Controllers\ImportacionArticulosController;
use App\Http\Controllers\InicioController;
use App\Http\Controllers\MovimientoController;
use App\Http\Controllers\OrdenCompraController;
use App\Http\Controllers\OrdenCompraPagoController;
use App\Http\Controllers\PedidoController;
use App\Http\Controllers\PedidoEntregaController;
use App\Http\Controllers\PedidoPagoController;
use App\Http\Controllers\ProveedorController;
use App\Http\Controllers\SaldoController;
use App\Http\Controllers\TransferenciaController;
use App\Http\Middleware\AsegurarUsuarioActivo;
use Illuminate\Support\Facades\Route;

Route::get('/', [InicioController::class, 'index'])->name('inicio');
Route::get('/estado', [InicioController::class, 'estado'])->name('estado');
Route::post('/eco', [InicioController::class, 'eco'])->name('eco');
Route::get('/estilos', EstilosController::class)->name('estilos');

// Portal de autofacturación (019): público, sin sesión. El token de 64
// caracteres es la autorización; son las únicas rutas que cualquiera puede llamar.
Route::middleware('throttle:autofactura')->group(function () {
    Route::get('autofactura/{token}', [AutofacturaController::class, 'show'])->where('token', '[A-Za-z0-9]{64}')->name('autofactura.show');
    Route::post('autofactura/{token}', [AutofacturaController::class, 'store'])->where('token', '[A-Za-z0-9]{64}')->name('autofactura.store');
});

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

    Route::resource('catalogos', CatalogoController::class)
        ->except('show')
        ->parameters(['catalogos' => 'catalogo']);

    Route::get('articulos/buscar', [ArticuloController::class, 'buscar'])->name('articulos.buscar');
    Route::get('articulos/sugerencias', [ArticuloController::class, 'sugerencias'])->name('articulos.sugerencias');
    Route::get('articulos/importar', [ImportacionArticulosController::class, 'create'])->name('articulos.importar');
    Route::post('articulos/importar', [ImportacionArticulosController::class, 'store'])->name('articulos.importar.store');
    Route::get('articulos/exportar', ExportacionArticulosController::class)->name('articulos.exportar');
    Route::get('articulos/imagenes', [ImagenesArticulosController::class, 'create'])->name('articulos.imagenes');
    Route::post('articulos/imagenes', [ImagenesArticulosController::class, 'store'])->name('articulos.imagenes.store');
    Route::get('articulos/{articulo}/imagen', [ImagenesArticulosController::class, 'show'])->withTrashed()->name('articulos.imagen');

    Route::resource('articulos', ArticuloController::class)
        ->except('show')
        ->parameters(['articulos' => 'articulo']);

    Route::get('cotizaciones/buscar', [CotizacionController::class, 'buscar'])->name('cotizaciones.buscar');
    Route::post('cotizaciones/{cotizacion}/enviar', [EnvioCotizacionController::class, 'correo'])->name('cotizaciones.enviar');
    Route::post('cotizaciones/{cotizacion}/marcar-enviada', [EnvioCotizacionController::class, 'marcarEnviada'])->name('cotizaciones.marcar-enviada');
    Route::get('cotizaciones/{cotizacion}/vista-previa', [CotizacionController::class, 'vistaPrevia'])->name('cotizaciones.vista-previa');
    Route::post('cotizaciones/{cotizacion}/timbrar', [FacturaController::class, 'timbrarCotizacion'])->name('cotizaciones.timbrar');
    Route::get('cotizaciones/{cotizacion}/pdf', [CotizacionController::class, 'pdf'])->name('cotizaciones.pdf');
    Route::post('cotizaciones/{cotizacion}/entregar', [CotizacionController::class, 'entregar'])->name('cotizaciones.entregar');
    Route::post('cotizaciones/{cotizacion}/duplicar', [CotizacionController::class, 'duplicar'])->name('cotizaciones.duplicar');
    Route::post('cotizaciones/{cotizacion}/aceptar', [CotizacionAceptacionController::class, 'store'])->name('cotizaciones.aceptar');
    Route::post('cotizaciones/{cotizacion}/pagos', [CotizacionPagoController::class, 'store'])->name('cotizaciones.pagos.store');
    Route::delete('cotizaciones/{cotizacion}/pagos/{pago}', [CotizacionPagoController::class, 'destroy'])
        ->scopeBindings()
        ->name('cotizaciones.pagos.destroy');

    // parameters(): sin él, Str::singular daría {cotizacione}.
    Route::resource('cotizaciones', CotizacionController::class)
        ->parameters(['cotizaciones' => 'cotizacion']);

    Route::get('facturas/buscar', [FacturaController::class, 'buscar'])->name('facturas.buscar');
    Route::get('facturas/cotizaciones', [FacturaController::class, 'cotizaciones'])->name('facturas.cotizaciones');
    Route::get('facturas/{factura}/vista-previa', [FacturaController::class, 'vistaPrevia'])->name('facturas.vista-previa');
    Route::post('facturas/{factura}/timbrar', [FacturaController::class, 'timbrar'])->name('facturas.timbrar');
    Route::post('facturas/{factura}/cancelar', [FacturaController::class, 'cancelar'])->name('facturas.cancelar');
    Route::get('facturas/{factura}/xml', [FacturaController::class, 'xml'])->name('facturas.xml');
    Route::get('facturas/{factura}/pdf', [FacturaController::class, 'pdf'])->name('facturas.pdf');
    Route::post('facturas/{factura}/enviar', [EnvioFacturaController::class, 'correo'])->name('facturas.enviar');
    Route::post('facturas/{factura}/complemento-pago', [ComplementoPagoController::class, 'store'])->name('facturas.complemento-pago');

    Route::resource('facturas', FacturaController::class)
        ->parameters(['facturas' => 'factura']);

    Route::get('ordenes-compra/buscar', [OrdenCompraController::class, 'buscar'])->name('ordenes-compra.buscar');
    Route::post('ordenes-compra/{ordenCompra}/enviar', [EnvioOrdenCompraController::class, 'correo'])->name('ordenes-compra.enviar');
    Route::post('ordenes-compra/{ordenCompra}/marcar-enviada', [EnvioOrdenCompraController::class, 'marcarEnviada'])->name('ordenes-compra.marcar-enviada');
    Route::get('ordenes-compra/{ordenCompra}/vista-previa', [OrdenCompraController::class, 'vistaPrevia'])->name('ordenes-compra.vista-previa');
    Route::get('ordenes-compra/{ordenCompra}/pdf', [OrdenCompraController::class, 'pdf'])->name('ordenes-compra.pdf');
    Route::post('ordenes-compra/{ordenCompra}/duplicar', [OrdenCompraController::class, 'duplicar'])->name('ordenes-compra.duplicar');
    Route::post('ordenes-compra/{ordenCompra}/recibir', [OrdenCompraController::class, 'recibir'])->name('ordenes-compra.recibir');
    Route::post('ordenes-compra/{ordenCompra}/pago', [OrdenCompraPagoController::class, 'store'])->name('ordenes-compra.pago.store');
    Route::delete('ordenes-compra/{ordenCompra}/pago', [OrdenCompraPagoController::class, 'destroy'])->name('ordenes-compra.pago.destroy');

    // parameters(): sin él, Str::singular daría un parámetro en inglés y el binding fallaría.
    Route::resource('ordenes-compra', OrdenCompraController::class)
        ->parameters(['ordenes-compra' => 'ordenCompra']);

    // Pedidos de mostrador (019). Las rutas estáticas van antes del resource.
    Route::get('pedidos/buscar', [PedidoController::class, 'buscar'])->name('pedidos.buscar');
    Route::get('pedidos/cliente-por-telefono', [PedidoController::class, 'clientePorTelefono'])->name('pedidos.cliente-por-telefono');
    Route::get('pedidos/{pedido}/ticket', [PedidoController::class, 'ticket'])->name('pedidos.ticket');
    Route::get('pedidos/{pedido}/etiqueta', [PedidoController::class, 'etiqueta'])->name('pedidos.etiqueta');
    Route::get('pedidos/{pedido}/entregar', [PedidoEntregaController::class, 'show'])->name('pedidos.entregar');
    Route::post('pedidos/{pedido}/entregar', [PedidoEntregaController::class, 'store'])->name('pedidos.entregar.store');
    Route::post('pedidos/{pedido}/deshacer-entrega', [PedidoEntregaController::class, 'destroy'])->name('pedidos.deshacer-entrega');
    Route::post('pedidos/{pedido}/pagos', [PedidoPagoController::class, 'store'])->name('pedidos.pagos.store');
    Route::delete('pedidos/{pedido}/pagos/{pago}', [PedidoPagoController::class, 'destroy'])
        ->scopeBindings()
        ->name('pedidos.pagos.destroy');

    Route::resource('pedidos', PedidoController::class)
        ->parameters(['pedidos' => 'pedido']);

    Route::get('configuracion', [ConfiguracionController::class, 'edit'])->name('configuracion.edit');
    Route::put('configuracion', [ConfiguracionController::class, 'update'])->name('configuracion.update');

    // Inventario. Las rutas estáticas van antes de {articulo}, o se tomarían
    // por un artículo.
    Route::prefix('existencias')->name('existencias.')->group(function () {
        Route::get('/', [ExistenciaController::class, 'index'])->name('index');
        Route::get('buscar', [ExistenciaController::class, 'buscar'])->name('buscar');
        Route::get('agregar', [ExistenciaController::class, 'agregar'])->name('agregar');
        Route::post('generar-ordenes-compra', GenerarOrdenesCompraController::class)->name('generar-ordenes-compra');
        Route::get('{articulo}', [ExistenciaController::class, 'show'])->name('show');
        Route::post('{articulo}/ajuste', [ExistenciaController::class, 'ajuste'])->name('ajuste');
        Route::put('{articulo}/parametros', [ExistenciaController::class, 'parametros'])->name('parametros');
        Route::delete('{articulo}', [ExistenciaController::class, 'destroy'])->name('destroy');
    });

    // Tesorería: en el menú se llama "Contabilidad".
    Route::prefix('tesoreria')->name('tesoreria.')->group(function () {
        Route::patch('cuentas/{cuenta}/activa', [CuentaController::class, 'alternarActiva'])->name('cuentas.activa');
        Route::resource('cuentas', CuentaController::class)->except('show');
        Route::resource('movimientos', MovimientoController::class)->except(['create', 'show']);
        Route::post('transferencias', [TransferenciaController::class, 'store'])->name('transferencias.store');
        Route::get('saldos', SaldoController::class)->name('saldos');
    });

    Route::get('catalogos-sat/claves-prod-serv', [CatalogoSatController::class, 'clavesProdServ'])->name('catalogos-sat.claves-prod-serv');
    Route::get('catalogos-sat/claves-unidad', [CatalogoSatController::class, 'clavesUnidad'])->name('catalogos-sat.claves-unidad');
});

require __DIR__.'/auth.php';
