<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', config('app.name'))</title>

    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <header class="barra">
        <a href="{{ auth()->check() ? route('dashboard') : route('inicio') }}" class="marca">{{ config('app.name') }}</a>

        <nav class="menu">
            @auth
                <a href="{{ route('dashboard') }}"><x-icono nombre="speedometer2" />Dashboard</a>
                <a href="{{ route('clientes.index') }}"><x-icono nombre="people" />Clientes</a>
                <a href="{{ route('proveedores.index') }}"><x-icono nombre="truck" />Proveedores</a>
                <a href="{{ route('catalogos.index') }}"><x-icono nombre="collection" />Catálogos</a>
                <a href="{{ route('articulos.index') }}"><x-icono nombre="box-seam" />Artículos</a>
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
