@extends('layouts.app')

@section('title', 'Artículos · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@section('content')
    <div class="encabezado">
        <h1>Artículos</h1>
        <div class="acciones">
            <x-boton :href="route('articulos.create')" icono="plus-lg">Nuevo artículo</x-boton>
            <x-boton :href="route('articulos.importar')" variante="secundario" icono="upload">Importar CSV</x-boton>
            <x-boton :href="route('articulos.imagenes')" variante="secundario" icono="images">Subir imágenes</x-boton>
            @include('articulos._exportar')
        </div>
    </div>

    @include('articulos._mensajes')

    {{-- Los campos de filtro y de filas por página se asocian con form="filtros-articulos". --}}
    <form id="filtros-articulos" method="GET" action="{{ route('articulos.index') }}" class="buscador" data-busqueda-dinamica="{{ route('articulos.buscar') }}">
        @include('articulos._orden')
        {{-- La búsqueda es dinámica; el botón solo existe como respaldo cuando no hay JavaScript. --}}
        <noscript><x-boton icono="search">Buscar</x-boton></noscript>
        <x-boton :href="route('articulos.index')" variante="secundario" icono="x-lg">Limpiar</x-boton>
    </form>

    <x-alerta tipo="error" hidden data-busqueda-error>No se pudo realizar la búsqueda. Intenta de nuevo.</x-alerta>

    <x-card class="tabla-contenedor">
        <table class="tabla tabla-fija" data-busqueda-tabla>
            <colgroup>
                <col>
                <col class="col-modelo">
                <col class="col-importe">
                <col class="col-precio">
                <col class="col-precio">
                <col class="col-existencias">
                <col class="col-acciones">
            </colgroup>
            <thead>
                @include('articulos._titulos')
                <tr class="tabla-filtros">
                    <th><x-campo nombre="nombre" etiqueta="Buscar por nombre" tipo="search" :valor="$filtros['nombre']" form="filtros-articulos" autocomplete="off" /></th>
                    <th><x-campo nombre="modelo" etiqueta="Buscar por modelo" tipo="search" :valor="$filtros['modelo']" form="filtros-articulos" autocomplete="off" /></th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th></th>
                </tr>
            </thead>
            @include('articulos._filas')
        </table>
    </x-card>

    <div class="pie-tabla">
        <x-campo nombre="por_pagina" etiqueta="Filas por página" tipo="select" :opciones="array_combine(App\Models\Articulo::POR_PAGINA, App\Models\Articulo::POR_PAGINA)" :valor="$porPagina" :vacia="false" form="filtros-articulos" />
        @include('articulos._paginacion')
    </div>

    @include('articulos._ficha')
@endsection

@push('scripts')
    <script src="{{ asset('js/busqueda-dinamica.js') }}?v={{ filemtime(public_path('js/busqueda-dinamica.js')) }}"></script>
    <script src="{{ asset('js/ficha-articulo.js') }}?v={{ filemtime(public_path('js/ficha-articulo.js')) }}"></script>
@endpush
