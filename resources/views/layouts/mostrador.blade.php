<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Mostrador · '.config('app.name'))</title>

    @include('layouts._pwa')

    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
{{-- Aplicación de mostrador (033): sin menú de aplicaciones ni de usuario. El
     nombre del sistema regresa a los tres accesos. --}}
<body class="mostrador">
    <header class="barra barra-mostrador">
        <a href="{{ route('mostrador.inicio') }}" class="marca" data-salir-captura>{{ config('app.name') }}</a>
        @hasSection('paso')
            <span class="mostrador-barra-paso">@yield('paso')</span>
        @endif
    </header>

    <main class="contenido contenido-mostrador">
        <x-alerta tipo="advertencia" hidden data-sin-conexion>Sin conexión. Revisa el internet e inténtalo de nuevo.</x-alerta>

        @if (session('exito'))
            <x-alerta tipo="exito">{{ session('exito') }}</x-alerta>
        @endif

        @if (session('error'))
            <x-alerta tipo="error">{{ session('error') }}</x-alerta>
        @endif

        @yield('content')
    </main>

    <script src="{{ asset('vendor/axios.min.js') }}"></script>
    <script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
    <script src="{{ asset('js/pwa.js') }}?v={{ filemtime(public_path('js/pwa.js')) }}"></script>
    @stack('scripts')
</body>
</html>
