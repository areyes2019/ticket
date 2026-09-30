@extends('layouts.app')

@section('title', 'Cotizaciones · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@section('content')
    <div class="encabezado">
        <h1>Cotizaciones</h1>
        <x-boton :href="route('cotizaciones.create')" icono="plus-lg">Nueva cotización</x-boton>
    </div>

    @include('documentos._mensajes')

    {{-- Los filtros de columna se asocian con form="filtros-cotizaciones". Las fechas y el periodo
         toman el valor de los atajos al pulsarlos (data-busqueda-sincronizar). --}}
    <form id="filtros-cotizaciones" method="GET" action="{{ route('cotizaciones.index') }}" class="buscador" data-busqueda-dinamica="{{ route('cotizaciones.buscar') }}">
        @include('cotizaciones._atajos')
        <input type="hidden" name="periodo" value="{{ $parametros['periodo'] ?? '' }}" data-busqueda-sincronizar>
        <x-campo nombre="fecha_desde" etiqueta="Desde" tipo="date" :valor="$fechaDesde" data-busqueda-sincronizar />
        <x-campo nombre="fecha_hasta" etiqueta="Hasta" tipo="date" :valor="$fechaHasta" data-busqueda-sincronizar />
        {{-- La búsqueda es dinámica; el botón solo existe como respaldo cuando no hay JavaScript. --}}
        <noscript><x-boton icono="search">Buscar</x-boton></noscript>
        <x-boton :href="route('cotizaciones.index')" variante="secundario" icono="x-lg">Limpiar</x-boton>
    </form>

    <x-alerta tipo="error" hidden data-busqueda-error>No se pudo realizar la búsqueda. Intenta de nuevo.</x-alerta>

    <x-card class="tabla-contenedor">
        <table class="tabla" data-busqueda-tabla>
            <thead>
                <tr>
                    <th>Folio</th>
                    <th>Cliente</th>
                    <th>RFC</th>
                    <th>Estado</th>
                    <th class="numero">Total</th>
                    <th>Fecha</th>
                    <th>Acciones</th>
                </tr>
                <tr class="tabla-filtros">
                    <th><x-campo nombre="folio" etiqueta="Filtrar por folio" tipo="search" :valor="$campos['folio']" form="filtros-cotizaciones" autocomplete="off" /></th>
                    <th><x-campo nombre="cliente" etiqueta="Filtrar por cliente" tipo="search" :valor="$campos['cliente']" form="filtros-cotizaciones" autocomplete="off" /></th>
                    <th><x-campo nombre="rfc" etiqueta="Filtrar por RFC" tipo="search" :valor="$campos['rfc']" form="filtros-cotizaciones" autocomplete="off" /></th>
                    <th><x-campo nombre="estado" etiqueta="Filtrar por estado" tipo="select" :opciones="App\Enums\EstadoCotizacion::opciones()" :valor="$campos['estado']" vacia="Todos" form="filtros-cotizaciones" /></th>
                    <th></th>
                    <th></th>
                    <th></th>
                </tr>
            </thead>
            @include('cotizaciones._filas')
        </table>
    </x-card>

    @include('cotizaciones._paginacion')
@endsection

@push('scripts')
    <script src="{{ asset('js/busqueda-dinamica.js') }}"></script>
@endpush
