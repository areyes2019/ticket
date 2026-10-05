@extends('layouts.app')

@section('title', 'Existencias · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@section('content')
    <div class="encabezado">
        <h1>Existencias</h1>
        <div class="acciones">
            <x-boton :href="route('existencias.agregar')" icono="plus-lg">Agregar artículo a existencias</x-boton>
            @if ($plan['grupos'] !== [])
                <x-boton href="#dialogo-generar" variante="secundario" icono="cart-plus" data-abrir-dialogo>Generar órdenes de compra</x-boton>
            @else
                <x-boton variante="secundario" icono="cart-plus" tipo="button" disabled title="No hay artículos por pedir">Generar órdenes de compra</x-boton>
            @endif
        </div>
    </div>

    @include('existencias._mensajes')

    @include('existencias._totales')

    {{-- Los campos de filtro se asocian con form="filtros-existencias". --}}
    <form id="filtros-existencias" method="GET" action="{{ route('existencias.index') }}" class="buscador" data-busqueda-dinamica="{{ route('existencias.buscar') }}">
        <x-campo nombre="q" id="filtro-texto" etiqueta="Buscar por nombre o modelo" tipo="search" :valor="$filtros['q']" autocomplete="off" />
        <x-campo nombre="catalogo" id="filtro-catalogo" etiqueta="Catálogo" tipo="select" :opciones="$catalogos" vacia="Todos" :valor="$filtros['catalogo']" />
        <x-campo nombre="proveedor" id="filtro-proveedor" etiqueta="Proveedor" tipo="select" :opciones="$proveedores" vacia="Todos" :valor="$filtros['proveedor']" />
        <div class="filtro-con-contador">
            <x-campo nombre="por_pedir" id="filtro-por-pedir" etiqueta="Mostrar" tipo="select" :opciones="['1' => 'Solo por pedir']" vacia="Todos" :valor="$filtros['por_pedir'] ? '1' : ''" />
            @include('existencias._contador')
        </div>
        @include('existencias._orden')
        {{-- La búsqueda es dinámica; el botón solo existe como respaldo cuando no hay JavaScript. --}}
        <noscript><x-boton icono="search">Buscar</x-boton></noscript>
        <x-boton :href="route('existencias.index')" variante="secundario" icono="x-lg">Limpiar</x-boton>
    </form>

    <x-alerta tipo="error" hidden data-busqueda-error>No se pudo realizar la búsqueda. Intenta de nuevo.</x-alerta>

    <x-card class="tabla-contenedor">
        <table class="tabla" data-busqueda-tabla>
            <thead>
                @include('existencias._titulos')
            </thead>
            @include('existencias._filas')
        </table>
    </x-card>

    @include('existencias._paginacion')

    @if ($plan['grupos'] !== [])
        @include('existencias._dialogo-generar')
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('js/busqueda-dinamica.js') }}?v={{ filemtime(public_path('js/busqueda-dinamica.js')) }}"></script>
@endpush
