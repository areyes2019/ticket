@extends('layouts.app')

@section('title', 'Facturas · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@section('content')
    <div class="encabezado">
        <h1>Facturas</h1>
        <x-boton :href="route('facturas.create')" icono="plus-lg">Nueva factura</x-boton>
    </div>

    @include('documentos._mensajes')

    {{-- Los filtros de columna se asocian con form="filtros-facturas". --}}
    <form id="filtros-facturas" method="GET" action="{{ route('facturas.index') }}" class="buscador" data-busqueda-dinamica="{{ route('facturas.buscar') }}">
        {{-- La búsqueda es dinámica; el botón solo existe como respaldo cuando no hay JavaScript. --}}
        <noscript><x-boton icono="search">Buscar</x-boton></noscript>
        <x-boton :href="route('facturas.index')" variante="secundario" icono="x-lg">Limpiar</x-boton>
    </form>

    <x-alerta tipo="error" hidden data-busqueda-error>No se pudo realizar la búsqueda. Intenta de nuevo.</x-alerta>
    <x-alerta tipo="error" hidden data-compartir-error></x-alerta>

    <x-card class="tabla-contenedor">
        <table class="tabla" data-busqueda-tabla>
            <thead>
                <tr>
                    <th>Folio</th>
                    <th>Cliente</th>
                    <th>RFC</th>
                    <th>UUID</th>
                    <th>Estado</th>
                    <th class="numero">Total</th>
                    <th>Fecha</th>
                    <th>Acciones</th>
                </tr>
                <tr class="tabla-filtros">
                    <th><x-campo nombre="folio" etiqueta="Filtrar por folio" tipo="search" :valor="$campos['folio']" form="filtros-facturas" autocomplete="off" /></th>
                    <th><x-campo nombre="cliente" etiqueta="Filtrar por cliente" tipo="search" :valor="$campos['cliente']" form="filtros-facturas" autocomplete="off" /></th>
                    <th><x-campo nombre="rfc" etiqueta="Filtrar por RFC" tipo="search" :valor="$campos['rfc']" form="filtros-facturas" autocomplete="off" /></th>
                    <th><x-campo nombre="uuid" etiqueta="Filtrar por UUID" tipo="search" :valor="$campos['uuid']" form="filtros-facturas" autocomplete="off" /></th>
                    <th><x-campo nombre="estado" etiqueta="Filtrar por estado" tipo="select" :opciones="App\Enums\EstadoFactura::opciones()" :valor="$campos['estado']" vacia="Todos" form="filtros-facturas" /></th>
                    <th></th>
                    <th></th>
                    <th></th>
                </tr>
            </thead>
            @include('facturas._filas')
        </table>
    </x-card>

    @include('facturas._paginacion')
@endsection

@push('scripts')
    <script src="{{ asset('js/busqueda-dinamica.js') }}"></script>
    <script src="{{ asset('js/compartir-pdf.js') }}"></script>
@endpush
