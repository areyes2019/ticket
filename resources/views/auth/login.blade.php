@extends('layouts.app')

@section('title', 'Iniciar sesión · '.config('app.name'))

@section('content')
    <x-card titulo="Iniciar sesión" :nivel="1" estrecha>
        @include('auth._mensajes')

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <x-campo nombre="email" etiqueta="Correo electrónico" tipo="email" required autofocus autocomplete="username" />
            <x-campo nombre="password" etiqueta="Contraseña" tipo="password" required autocomplete="current-password" />
            <x-campo nombre="remember" etiqueta="Recordarme" tipo="checkbox" />

            <x-boton icono="box-arrow-in-right" bloque>Iniciar sesión</x-boton>
        </form>

        <p class="enlaces-auth">
            <a href="{{ route('password.request') }}">¿Olvidaste tu contraseña?</a>
            <a href="{{ route('register') }}">Crear cuenta</a>
        </p>
    </x-card>
@endsection

@push('scripts')
    <script src="{{ asset('js/contrasena.js') }}?v={{ filemtime(public_path('js/contrasena.js')) }}"></script>
@endpush
