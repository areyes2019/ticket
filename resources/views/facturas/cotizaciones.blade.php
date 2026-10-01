@extends('layouts.app')

@section('title', 'Facturar una cotización · '.config('app.name'))

{{-- Respaldo sin JavaScript de la ventana "Desde cotización" del listado. --}}
@section('content')
    <div class="encabezado">
        <h1>Facturar una cotización</h1>
        <x-boton :href="route('facturas.index')" variante="secundario" icono="arrow-left">Facturas</x-boton>
    </div>

    <form method="GET" action="{{ route('facturas.cotizaciones') }}" class="buscador">
        <x-campo nombre="q" etiqueta="Buscar cotización" tipo="search" :valor="$texto" placeholder="Folio, cliente o RFC" autocomplete="off" />
        <x-boton icono="search">Buscar</x-boton>
    </form>

    <x-card>
        @include('facturas._cotizaciones-facturables')
    </x-card>
@endsection
