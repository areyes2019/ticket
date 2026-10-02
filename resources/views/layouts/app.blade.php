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
<body @auth class="con-menu-apps" @endauth>
    @auth
        <x-menu-apps />
    @endauth

    <header class="barra">
        <a href="{{ auth()->check() ? route('dashboard') : route('inicio') }}" class="marca">{{ config('app.name') }}</a>

        <nav class="menu">
            @auth
                <a href="{{ route('tesoreria.movimientos.index') }}"><x-icono nombre="cash-coin" />Contabilidad</a>
                <a href="{{ route('configuracion.edit') }}" @if (request()->routeIs('configuracion.*')) aria-current="page" @endif><x-icono nombre="gear" />Configuración</a>
                @can('ver-historial-accesos')
                    <a href="{{ route('historial-accesos.index') }}"><x-icono nombre="clock-history" />Historial de accesos</a>
                @endcan
                <details class="menu-grupo menu-usuario" data-menu-grupo>
                    <summary><x-icono nombre="person-circle" />{{ auth()->user()->name }}<x-icono nombre="chevron-down" class="menu-grupo-flecha" /></summary>
                    <div class="menu-panel menu-panel-derecha">
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-boton variante="menu">
                                <span class="menu-panel-icono"><x-icono nombre="box-arrow-right" /></span>
                                <span class="menu-panel-texto"><strong>Cerrar sesión</strong></span>
                            </x-boton>
                        </form>
                    </div>
                </details>
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
