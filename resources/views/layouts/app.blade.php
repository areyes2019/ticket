<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', config('app.name'))</title>

    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>
    <header class="barra">
        <a href="{{ auth()->check() ? route('dashboard') : route('inicio') }}" class="marca">{{ config('app.name') }}</a>

        <nav class="menu">
            @auth
                <a href="{{ route('dashboard') }}"><x-icono nombre="speedometer2" />Dashboard</a>
                <details class="menu-grupo" data-menu-grupo>
                    <summary @if (request()->routeIs('proveedores.*', 'ordenes-compra.*')) class="menu-grupo-activo" @endif>
                        <x-icono nombre="bag" />Compras<x-icono nombre="chevron-down" class="menu-grupo-flecha" />
                    </summary>
                    <div class="menu-panel">
                        <a href="{{ route('proveedores.index') }}" @if (request()->routeIs('proveedores.*')) aria-current="page" @endif>
                            <span class="menu-panel-icono"><x-icono nombre="truck" /></span>
                            <span class="menu-panel-texto"><strong>Proveedores</strong><small>Directorio de quienes te venden</small></span>
                        </a>
                        <a href="{{ route('ordenes-compra.index') }}" @if (request()->routeIs('ordenes-compra.*')) aria-current="page" @endif>
                            <span class="menu-panel-icono"><x-icono nombre="cart" /></span>
                            <span class="menu-panel-texto"><strong>Órdenes de compra</strong><small>Pedidos, pagos y recepción</small></span>
                        </a>
                    </div>
                </details>
                <details class="menu-grupo" data-menu-grupo>
                    <summary @if (request()->routeIs('facturas.*', 'cotizaciones.*', 'clientes.*')) class="menu-grupo-activo" @endif>
                        <x-icono nombre="graph-up-arrow" />Ventas<x-icono nombre="chevron-down" class="menu-grupo-flecha" />
                    </summary>
                    <div class="menu-panel">
                        <a href="{{ route('facturas.index') }}" @if (request()->routeIs('facturas.*')) aria-current="page" @endif>
                            <span class="menu-panel-icono"><x-icono nombre="receipt" /></span>
                            <span class="menu-panel-texto"><strong>Facturas</strong><small>Comprobantes emitidos y timbrado</small></span>
                        </a>
                        <a href="{{ route('cotizaciones.index') }}" @if (request()->routeIs('cotizaciones.*')) aria-current="page" @endif>
                            <span class="menu-panel-icono"><x-icono nombre="file-earmark-text" /></span>
                            <span class="menu-panel-texto"><strong>Cotizaciones</strong><small>Propuestas para tus clientes</small></span>
                        </a>
                        <a href="{{ route('clientes.index') }}" @if (request()->routeIs('clientes.*')) aria-current="page" @endif>
                            <span class="menu-panel-icono"><x-icono nombre="people" /></span>
                            <span class="menu-panel-texto"><strong>Clientes</strong><small>Datos fiscales y de contacto</small></span>
                        </a>
                    </div>
                </details>
                <details class="menu-grupo" data-menu-grupo>
                    <summary @if (request()->routeIs('articulos.*', 'catalogos.*', 'existencias.*')) class="menu-grupo-activo" @endif>
                        <x-icono nombre="boxes" />Inventario<x-icono nombre="chevron-down" class="menu-grupo-flecha" />
                    </summary>
                    <div class="menu-panel">
                        <a href="{{ route('articulos.index') }}" @if (request()->routeIs('articulos.*')) aria-current="page" @endif>
                            <span class="menu-panel-icono"><x-icono nombre="box-seam" /></span>
                            <span class="menu-panel-texto"><strong>Artículos</strong><small>Catálogo general con precios</small></span>
                        </a>
                        <a href="{{ route('catalogos.index') }}" @if (request()->routeIs('catalogos.*')) aria-current="page" @endif>
                            <span class="menu-panel-icono"><x-icono nombre="collection" /></span>
                            <span class="menu-panel-texto"><strong>Catálogos</strong><small>Listas de cada proveedor</small></span>
                        </a>
                        <a href="{{ route('existencias.index') }}" @if (request()->routeIs('existencias.*')) aria-current="page" @endif>
                            <span class="menu-panel-icono"><x-icono nombre="boxes" /></span>
                            <span class="menu-panel-texto"><strong>Existencias</strong><small>Piezas en bodega y reposición</small></span>
                        </a>
                    </div>
                </details>
                <a href="{{ route('tesoreria.movimientos.index') }}"><x-icono nombre="cash-coin" />Contabilidad</a>
                @can('ver-historial-accesos')
                    <a href="{{ route('historial-accesos.index') }}"><x-icono nombre="clock-history" />Historial de accesos</a>
                @endcan
                <span class="menu-usuario">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-boton variante="secundario" icono="box-arrow-right">Cerrar sesión</x-boton>
                </form>
            @else
                <a href="{{ route('login') }}"><x-icono nombre="box-arrow-in-right" />Iniciar sesión</a>
                <a href="{{ route('register') }}"><x-icono nombre="person-plus" />Crear cuenta</a>
            @endauth
        </nav>
    </header>

    <main class="contenido @yield('contenido-clase')">
        @yield('content')
    </main>

    <script src="{{ asset('vendor/axios.min.js') }}"></script>
    <script src="{{ asset('js/app.js') }}"></script>
    @stack('scripts')
</body>
</html>
