@extends('layouts.app')

@section('title', 'Órdenes de compra · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@section('content')
    <div class="encabezado">
        <h1>Órdenes de compra</h1>
        <x-boton :href="route('ordenes-compra.create')" icono="plus-lg">Nueva orden de compra</x-boton>
    </div>

    @include('documentos._mensajes')

    {{-- Los filtros de columna se asocian con form="filtros-ordenes". Las fechas y el periodo
         toman el valor de los atajos al pulsarlos (data-busqueda-sincronizar). --}}
    <form id="filtros-ordenes" method="GET" action="{{ route('ordenes-compra.index') }}" class="buscador" data-busqueda-dinamica="{{ route('ordenes-compra.buscar') }}">
        @include('ordenes-compra._atajos')
        <input type="hidden" name="periodo" value="{{ $periodo === App\Http\Requests\ListadoOrdenesCompraRequest::PERIODO_DEFECTO ? '' : $periodo }}" data-busqueda-sincronizar>
        <x-campo nombre="fecha_desde" etiqueta="Desde" tipo="date" :valor="$fechaDesde" data-busqueda-sincronizar />
        <x-campo nombre="fecha_hasta" etiqueta="Hasta" tipo="date" :valor="$fechaHasta" data-busqueda-sincronizar />
        {{-- La búsqueda es dinámica; el botón solo existe como respaldo cuando no hay JavaScript. --}}
        <noscript><x-boton icono="search">Buscar</x-boton></noscript>
        <x-boton :href="route('ordenes-compra.index')" variante="secundario" icono="x-lg">Limpiar</x-boton>
    </form>

    <x-alerta tipo="error" hidden data-busqueda-error>No se pudo realizar la búsqueda. Intenta de nuevo.</x-alerta>

    <x-card class="tabla-contenedor">
        <table class="tabla" data-busqueda-tabla>
            <thead>
                <tr>
                    <th>Folio</th>
                    <th>Proveedor</th>
                    <th>RFC</th>
                    <th>Estado</th>
                    <th class="numero">Total</th>
                    <th>Fecha</th>
                    <th>Acciones</th>
                </tr>
                <tr class="tabla-filtros">
                    <th><x-campo nombre="folio" etiqueta="Filtrar por folio" tipo="search" :valor="$campos['folio']" form="filtros-ordenes" autocomplete="off" /></th>
                    <th><x-campo nombre="proveedor" etiqueta="Filtrar por proveedor" tipo="search" :valor="$campos['proveedor']" form="filtros-ordenes" autocomplete="off" /></th>
                    <th><x-campo nombre="rfc" etiqueta="Filtrar por RFC" tipo="search" :valor="$campos['rfc']" form="filtros-ordenes" autocomplete="off" /></th>
                    <th><x-campo nombre="estado" etiqueta="Filtrar por estado" tipo="select" :opciones="App\Enums\EstadoOrdenCompra::opciones()" :valor="$campos['estado']" vacia="Todos" form="filtros-ordenes" /></th>
                    <th></th>
                    <th></th>
                    <th></th>
                </tr>
            </thead>
            @include('ordenes-compra._filas')
        </table>
    </x-card>

    @include('ordenes-compra._paginacion')
@endsection

@push('scripts')
    <script src="{{ asset('js/busqueda-dinamica.js') }}"></script>
@endpush
