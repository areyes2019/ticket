@extends('layouts.app')

@section('title', 'Artículos IA · '.config('app.name'))

@section('content')
    <div class="encabezado">
        <h1>Artículos IA</h1>
        <div class="acciones">
            <x-boton :href="route('articulos-ia.agregar')" icono="plus-lg">Agregar</x-boton>
        </div>
    </div>

    @include('existencias._mensajes')

    <p class="ayuda">El agente de ventas de WhatsApp busca primero en esta lista. Si nada coincide con lo que pide el cliente, o el cliente pide algo muy especial, busca en todo el catálogo.</p>

    <form method="GET" action="{{ route('articulos-ia.index') }}" class="buscador" data-busqueda-dinamica="{{ route('articulos-ia.buscar') }}">
        <x-campo nombre="q" id="filtro-texto" etiqueta="Buscar por nombre o modelo" tipo="search" :valor="$texto" autocomplete="off" />
        {{-- La búsqueda es dinámica; el botón solo existe como respaldo cuando no hay JavaScript. --}}
        <noscript><x-boton icono="search">Buscar</x-boton></noscript>
        <x-boton :href="route('articulos-ia.index')" variante="secundario" icono="x-lg">Limpiar</x-boton>
    </form>

    <x-alerta tipo="error" hidden data-busqueda-error>No se pudo realizar la búsqueda. Intenta de nuevo.</x-alerta>

    <x-card class="tabla-contenedor">
        <table class="tabla" data-busqueda-tabla>
            <thead>
                @include('articulos-ia._titulos')
            </thead>
            @include('articulos-ia._filas')
        </table>
    </x-card>

    @include('articulos-ia._paginacion')
@endsection

@push('scripts')
    <script src="{{ asset('js/busqueda-dinamica.js') }}?v={{ filemtime(public_path('js/busqueda-dinamica.js')) }}"></script>
@endpush
