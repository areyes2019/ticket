@extends('layouts.app')

@section('title', '¿Olvidaste tu contraseña? · '.config('app.name'))

@section('content')
    <x-card titulo="¿Olvidaste tu contraseña?" :nivel="1" estrecha>
        <p>Escribe tu correo y te enviaremos un enlace para crear una contraseña nueva.</p>

        @include('auth._mensajes')

        <form method="POST" action="{{ route('password.email') }}">
            @csrf

            <x-campo nombre="email" etiqueta="Correo electrónico" tipo="email" required autofocus autocomplete="username" />

            <x-boton icono="envelope" bloque>Enviar enlace</x-boton>
        </form>

        <p class="enlaces-auth">
            <a href="{{ route('login') }}">Volver a iniciar sesión</a>
        </p>
    </x-card>
@endsection
