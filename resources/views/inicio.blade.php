@extends('layouts.app')

@section('title', 'Inicio · '.config('app.name'))

@section('content')
    <h1>{{ config('app.name') }}</h1>
    <p>Laravel {{ app()->version() }} · Blade · JavaScript nativo · Axios</p>

    <x-card titulo="Interfaz con JavaScript">
        <p>Clics: <strong id="contador">0</strong></p>
        <x-boton tipo="button" icono="plus-lg" id="btn-contador">Sumar</x-boton>
    </x-card>

    <x-card titulo="Petición GET con Axios">
        <x-boton tipo="button" icono="cloud-download" id="btn-estado">Consultar estado</x-boton>
        <pre id="resultado-estado" class="resultado"></pre>
    </x-card>

    <x-card titulo="Petición POST con Axios (CSRF)">
        <form id="form-eco">
            <x-campo nombre="mensaje" etiqueta="Mensaje" placeholder="Escribe un mensaje" autocomplete="off" />
            <x-boton icono="send">Enviar</x-boton>
        </form>
        <pre id="resultado-eco" class="resultado"></pre>
    </x-card>
@endsection

@push('scripts')
    <script src="{{ asset('js/inicio.js') }}"></script>
@endpush
