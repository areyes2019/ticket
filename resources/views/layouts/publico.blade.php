<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <title>@yield('title', config('negocio.nombre'))</title>

    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>
    {{-- Páginas que ve el cliente del negocio, no el usuario del sistema: sin menú ni acceso. --}}
    <header class="barra barra-publica">
        <span class="marca">{{ config('negocio.nombre') }}</span>
    </header>

    <main class="contenido">
        @yield('content')
    </main>

    {{-- app.js da data-enviar-una-vez y los diálogos; necesita Axios para su configuración. --}}
    <script src="{{ asset('vendor/axios.min.js') }}"></script>
    <script src="{{ asset('js/app.js') }}"></script>
</body>
</html>
