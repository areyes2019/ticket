@php
    // En el dashboard, ?app=correo llega con la aplicación ya abierta (el enlace
    // a Correo se quitó del menú; el módulo sigue disponible por la dirección).
    $appAbierta = request()->routeIs('dashboard') ? request('app') : null;

    $grupos = [
        [
            'nombre' => 'Ventas',
            'icono' => 'graph-up-arrow',
            'rutas' => ['pedidos.*', 'facturas.*', 'cotizaciones.*', 'clientes.*'],
            'opciones' => [
                ['ruta' => 'pedidos', 'icono' => 'ticket-perforated', 'nombre' => 'Ventas', 'detalle' => 'Mostrador y cotizaciones aceptadas'],
                ['ruta' => 'facturas', 'icono' => 'receipt', 'nombre' => 'Facturas', 'detalle' => 'Comprobantes emitidos y timbrado'],
                ['ruta' => 'cotizaciones', 'icono' => 'file-earmark-text', 'nombre' => 'Cotizaciones', 'detalle' => 'Propuestas para tus clientes'],
                ['ruta' => 'clientes', 'icono' => 'people', 'nombre' => 'Clientes', 'detalle' => 'Datos fiscales y de contacto'],
            ],
        ],
        [
            'nombre' => 'Compras',
            'icono' => 'bag',
            'rutas' => ['proveedores.*', 'ordenes-compra.*'],
            'opciones' => [
                ['ruta' => 'proveedores', 'icono' => 'truck', 'nombre' => 'Proveedores', 'detalle' => 'Directorio de quienes te venden'],
                ['ruta' => 'ordenes-compra', 'icono' => 'cart', 'nombre' => 'Órdenes de compra', 'detalle' => 'Pedidos, pagos y recepción'],
            ],
        ],
        [
            'nombre' => 'Inventario',
            'icono' => 'boxes',
            'rutas' => ['articulos.*', 'catalogos.*', 'existencias.*'],
            'opciones' => [
                ['ruta' => 'articulos', 'icono' => 'box-seam', 'nombre' => 'Artículos', 'detalle' => 'Catálogo general con precios'],
                ['ruta' => 'catalogos', 'icono' => 'collection', 'nombre' => 'Catálogos', 'detalle' => 'Listas de cada proveedor'],
                ['ruta' => 'existencias', 'icono' => 'boxes', 'nombre' => 'Existencias', 'detalle' => 'Piezas en bodega y reposición'],
            ],
        ],
        [
            'nombre' => 'Contabilidad',
            'icono' => 'cash-coin',
            'rutas' => ['tesoreria.*'],
            'opciones' => [
                ['ruta' => 'tesoreria.movimientos', 'icono' => 'arrow-left-right', 'nombre' => 'Movimientos', 'detalle' => 'Ingresos, egresos y transferencias'],
                ['ruta' => 'tesoreria.cuentas', 'icono' => 'wallet2', 'nombre' => 'Cuentas', 'detalle' => 'Bancos, caja y tarjetas'],
                // Saldos es una sola ruta, sin .index.
                ['ruta' => 'tesoreria.saldos', 'indice' => 'tesoreria.saldos', 'icono' => 'bank', 'nombre' => 'Saldos', 'detalle' => 'Cuánto hay en cada cuenta'],
            ],
        ],
    ];
@endphp

{{-- Menú de aplicaciones: barra fija a la izquierda en todo el sistema (abajo en
     celular). En el dashboard, Dashboard cambia la aplicación sin recargar
     (dashboard-apps.js); en otras páginas es un enlace normal. Los grupos son <details
     data-menu-grupo>, que app.js cierra al hacer clic fuera. --}}
<nav {{ $attributes->class('menu-apps') }} aria-label="Aplicaciones">
    <a href="{{ route('dashboard') }}" class="menu-apps-opcion" title="Dashboard" data-abrir-app=""
       @if (request()->routeIs('dashboard') && ! $appAbierta) aria-current="page" @endif>
        <span class="menu-apps-icono"><x-icono nombre="speedometer2" /></span>
        <span class="menu-apps-texto">Dashboard</span>
    </a>

    @foreach ($grupos as $grupo)
        <details class="menu-apps-grupo" data-menu-grupo>
            <summary @class(['menu-apps-opcion', 'menu-apps-seccion-activa' => request()->routeIs(...$grupo['rutas'])]) title="{{ $grupo['nombre'] }}">
                <span class="menu-apps-icono"><x-icono :nombre="$grupo['icono']" /></span>
                <span class="menu-apps-texto">{{ $grupo['nombre'] }}</span>
            </summary>
            <div class="menu-panel">
                <p class="menu-panel-titulo">{{ $grupo['nombre'] }}</p>
                @foreach ($grupo['opciones'] as $opcion)
                    <a href="{{ route($opcion['indice'] ?? $opcion['ruta'].'.index') }}" @if (request()->routeIs($opcion['ruta'], $opcion['ruta'].'.*')) aria-current="page" @endif>
                        <span class="menu-panel-icono"><x-icono :nombre="$opcion['icono']" /></span>
                        <span class="menu-panel-texto"><strong>{{ $opcion['nombre'] }}</strong><small>{{ $opcion['detalle'] }}</small></span>
                    </a>
                @endforeach
            </div>
        </details>
    @endforeach
</nav>
