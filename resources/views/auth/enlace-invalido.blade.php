@extends('layouts.app')

@section('title', 'Enlace no válido · '.config('app.name'))

@section('content')
    <x-card titulo="Enlace no válido" :nivel="1" estrecha>
        <x-alerta tipo="error">{{ __('passwords.token') }}</x-alerta>
        <p>Los enlaces para crear una contraseña nueva duran {{ config('auth.passwords.users.expire') }} minutos y solo se pueden usar una vez.</p>
        <x-boton :href="route('password.request')" icono="envelope" bloque>Pedir un enlace nuevo</x-boton>
    </x-card>
@endsection
