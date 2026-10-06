@extends('layouts.app')

@section('title', 'Ventas · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@section('content')
    <div class="encabezado">
        <h1>Ventas</h1>
        <div class="acciones">
            <x-boton :href="route('pedidos.produccion')" variante="secundario" icono="printer" target="_blank">Hoja de producción</x-boton>
            <x-boton :href="route('pedidos.produccion.etiquetas')" variante="secundario" icono="tags" target="_blank">Imprimir etiquetas</x-boton>
            <x-boton :href="route('pedidos.create')" icono="plus-lg">Nueva venta</x-boton>
        </div>
    </div>

    @include('documentos._mensajes')

    {{-- Los campos de filtro están en el encabezado de la tabla y se asocian con form="filtros-pedidos". --}}
    <form id="filtros-pedidos" method="GET" action="{{ route('pedidos.index') }}" class="buscador" data-busqueda-dinamica="{{ route('pedidos.buscar') }}">
        <x-campo nombre="periodo" etiqueta="Periodo" tipo="select" :opciones="$periodos" :valor="$filtros['periodo']" :vacia="false" />
        <x-campo nombre="origen" etiqueta="Origen" tipo="select" :opciones="$origenes" :valor="$filtros['origen']" vacia="Todas" />
        {{-- La búsqueda es dinámica; el botón solo existe como respaldo cuando no hay JavaScript. --}}
        <noscript><x-boton icono="search">Buscar</x-boton></noscript>
        <x-boton :href="route('pedidos.index')" variante="secundario" icono="x-lg">Limpiar</x-boton>
    </form>

    <x-alerta tipo="error" hidden data-busqueda-error>No se pudo realizar la búsqueda. Intenta de nuevo.</x-alerta>

    <x-card class="tabla-contenedor">
        <table class="tabla" data-busqueda-tabla>
            <thead>
                <tr>
                    <th>Folio</th>
                    <th>Cliente</th>
                    <th>Teléfono</th>
                    <th>Fecha</th>
                    <th class="numero">Total</th>
                    <th class="numero">Pagado</th>
                    <th class="numero">Saldo</th>
                    <th>Estado</th>
                    <th><span class="solo-lectores">Acciones</span></th>
                </tr>
                <tr class="tabla-filtros">
                    <th><x-campo nombre="folio" etiqueta="Filtrar por folio" tipo="search" :valor="$filtros['folio']" form="filtros-pedidos" autocomplete="off" /></th>
                    <th><x-campo nombre="cliente" etiqueta="Filtrar por cliente" tipo="search" :valor="$filtros['cliente']" form="filtros-pedidos" autocomplete="off" /></th>
                    <th><x-campo nombre="telefono" etiqueta="Filtrar por teléfono" tipo="search" :valor="$filtros['telefono']" form="filtros-pedidos" autocomplete="off" inputmode="tel" /></th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th></th>
                    <th><x-campo nombre="estado" etiqueta="Filtrar por estado" tipo="select" :opciones="$estados" :valor="$filtros['estado']" vacia="Todos" form="filtros-pedidos" /></th>
                    <th></th>
                </tr>
            </thead>
            @include('pedidos._filas')
        </table>
    </x-card>

    @include('pedidos._paginacion')
@endsection

@push('scripts')
    <script src="{{ asset('js/busqueda-dinamica.js') }}?v={{ filemtime(public_path('js/busqueda-dinamica.js')) }}"></script>
@endpush
