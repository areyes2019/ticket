@extends('layouts.app')

@section('title', 'Crear cuenta · '.config('app.name'))

@section('content')
    <x-card titulo="Crear cuenta" :nivel="1" estrecha>
        @include('auth._mensajes')

        <form method="POST" action="{{ route('register') }}">
            @csrf

            <x-campo nombre="name" etiqueta="Nombre" required autofocus autocomplete="name" />
            <x-campo nombre="email" etiqueta="Correo electrónico" tipo="email" required autocomplete="username" />
            <x-campo nombre="password" etiqueta="Contraseña" tipo="password" required autocomplete="new-password"
                ayuda="Mínimo 8 caracteres, con mayúscula, minúscula, número y símbolo." />
            <x-campo nombre="password_confirmation" etiqueta="Confirmar contraseña" tipo="password" required autocomplete="new-password" />

            <x-boton icono="person-plus" bloque>Crear cuenta</x-boton>
        </form>

        <p class="enlaces-auth">
            <a href="{{ route('login') }}">¿Ya tienes cuenta? Inicia sesión</a>
        </p>
    </x-card>
@endsection

@push('scripts')
    <script src="{{ asset('js/contrasena.js') }}?v={{ filemtime(public_path('js/contrasena.js')) }}"></script>
@endpush
