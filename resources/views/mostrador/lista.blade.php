@extends('layouts.mostrador')

@php
    // Las tres listas de consulta (034) son esta misma pantalla con otras tarjetas.
    $config = [
        'cotizaciones' => ['titulo' => 'Cotizaciones', 'buscar' => 'Folio, cliente o RFC', 'ayuda' => 'Últimos 30 días. Busca para ver anteriores.'],
        'facturas' => ['titulo' => 'Facturas', 'buscar' => 'Folio, cliente, RFC o UUID', 'ayuda' => 'Últimos 30 días. Busca para ver anteriores.'],
        'catalogo' => ['titulo' => 'Catálogo', 'buscar' => 'Nombre, modelo o proveedor', 'ayuda' => null],
    ][$seccion];
@endphp

@section('title', $config['titulo'].' · Mostrador')
@section('barra', $seccion)

@section('content')
    {{--
        Lista de consulta del mostrador (034). La primera página (o las que ya
        se habían cargado, ?paginas=n) la pinta el servidor; mostrador.js pide
        las siguientes al llegar al final y deja el texto buscado y las páginas
        en la URL, así "atrás" desde un detalle regresa a la misma altura.
    --}}
    <div class="mostrador-consulta" data-lista-consulta data-tarjetas="{{ route('mostrador.tarjetas.'.$seccion) }}">
        <p class="mostrador-indicador"><strong>{{ $config['titulo'] }}</strong></p>

        <x-campo nombre="q" etiqueta="Buscar" tipo="search" :valor="$q" :placeholder="$config['buscar']" :ayuda="$config['ayuda']" autocomplete="off" data-buscar-consulta />

        <div @class(['mostrador-lista', 'mostrador-lista-articulos' => $seccion === 'catalogo']) data-lista aria-live="polite">
            @include('mostrador._tarjetas-'.$seccion)
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/mostrador.js') }}?v={{ filemtime(public_path('js/mostrador.js')) }}"></script>
@endpush
