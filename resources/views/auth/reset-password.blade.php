@extends('layouts.app')

@section('title', 'Nueva contraseña · '.config('app.name'))

@section('content')
    <x-card titulo="Nueva contraseña" :nivel="1" estrecha>
        @include('auth._mensajes')

        <form method="POST" action="{{ route('password.store') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="email" value="{{ $email }}">

            <p class="ayuda">Cuenta: <strong>{{ $email }}</strong></p>

            <x-campo nombre="password" etiqueta="Nueva contraseña" tipo="password" required autocomplete="new-password"
                ayuda="Mínimo 8 caracteres, con mayúscula, minúscula, número y símbolo." />
            <x-campo nombre="password_confirmation" etiqueta="Confirmar contraseña" tipo="password" required autocomplete="new-password" />

            <x-boton icono="key" bloque>Guardar contraseña</x-boton>
        </form>
    </x-card>
@endsection

@push('scripts')
    <script src="{{ asset('js/contrasena.js') }}"></script>
@endpush
