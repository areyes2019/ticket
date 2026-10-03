@extends('layouts.app')

@section('title', 'Clientes · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@section('content')
    <div class="encabezado">
        <h1>Clientes</h1>
        <x-boton :href="route('clientes.create')" icono="plus-lg">Nuevo cliente</x-boton>
    </div>

    @include('clientes._mensajes')

    {{-- Los campos de filtro están en el encabezado de la tabla y se asocian con form="filtros-clientes". --}}
    <form id="filtros-clientes" method="GET" action="{{ route('clientes.index') }}" class="buscador" data-busqueda-dinamica="{{ route('clientes.buscar') }}">
        {{-- La búsqueda es dinámica; el botón solo existe como respaldo cuando no hay JavaScript. --}}
        <noscript><x-boton icono="search">Buscar</x-boton></noscript>
        <x-boton :href="route('clientes.index')" variante="secundario" icono="x-lg">Limpiar</x-boton>
    </form>

    <x-alerta tipo="error" hidden data-busqueda-error>No se pudo realizar la búsqueda. Intenta de nuevo.</x-alerta>

    <x-card class="tabla-contenedor">
        <table class="tabla" data-busqueda-tabla>
            <thead>
                <tr>
                    <th>Razón social</th>
                    <th>Nom. Comercial</th>
                    <th>Contacto</th>
                    <th>RFC</th>
                    <th>Régimen</th>
                    <th>Teléfono</th>
                    <th class="numero">Descuento</th>
                    <th>Acciones</th>
                </tr>
                <tr class="tabla-filtros">
                    @foreach (['razon_social' => 'razón social', 'nombre_comercial' => 'nombre comercial', 'nombre_contacto' => 'contacto', 'rfc' => 'RFC'] as $filtro => $etiqueta)
                        <th>
                            <x-campo :nombre="$filtro" :etiqueta="'Filtrar por '.$etiqueta" tipo="search" :valor="$filtros[$filtro]" form="filtros-clientes" autocomplete="off" />
                        </th>
                    @endforeach
                    <th></th>
                    <th></th>
                    <th></th>
                    <th></th>
                </tr>
            </thead>
            @include('clientes._filas')
        </table>
    </x-card>

    @include('clientes._paginacion')
@endsection

@push('scripts')
    <script src="{{ asset('js/busqueda-dinamica.js') }}"></script>
@endpush
