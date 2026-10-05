@extends('layouts.app')

@section('title', 'Órdenes de compra · '.config('app.name'))
@section('contenido-clase', 'contenido-bandeja')

@section('content')
    <h1 class="solo-lectores">Órdenes de compra</h1>

    @include('documentos._mensajes')

    @foreach (['envio', 'pago'] as $bolsa)
        @if ($errors->{$bolsa}->any())
            <x-alerta tipo="error">
                @foreach ($errors->{$bolsa}->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </x-alerta>
        @endif
    @endforeach

    <x-alerta tipo="error" hidden data-compartir-error></x-alerta>
    <x-alerta tipo="error" hidden data-busqueda-error>No se pudo realizar la búsqueda. Intenta de nuevo.</x-alerta>
    <x-alerta tipo="error" hidden data-vista-previa-error>No se pudo abrir la orden de compra. Intenta de nuevo.</x-alerta>

    {{-- Carpeta y etiqueta viajan ocultas: los enlaces de la izquierda las
         cambian (data-busqueda-sincronizar). El buscador se asocia con form="…". --}}
    <form id="filtros-ordenes" method="GET" action="{{ route('ordenes-compra.index') }}" data-busqueda-dinamica="{{ route('ordenes-compra.buscar') }}" hidden>
        <input type="hidden" name="periodo" value="{{ $parametros['periodo'] ?? '' }}" data-busqueda-sincronizar>
        <input type="hidden" name="estado" value="{{ $estado }}" data-busqueda-sincronizar>
    </form>

    <div class="bandeja" data-bandeja-documentos data-parametro="orden" data-sin-seleccion="Selecciona una orden de compra">
        @include('ordenes-compra._carpetas')

        <section class="bandeja-lista" aria-label="Lista de órdenes de compra" data-busqueda-tabla>
            <x-bandeja.encabezado-lista texto="Buscar por folio, proveedor o RFC" nombre="q" :valor="$texto" formulario="filtros-ordenes" />

            @include('ordenes-compra._filas')
            @include('ordenes-compra._paginacion')
        </section>

        <section class="bandeja-visor bandeja-visor-documento" aria-label="Orden de compra abierta" data-visor-documento>
            @if ($abierta)
                @include('ordenes-compra._vista-previa', ['orden' => $abierta])
            @else
                <p class="bandeja-sin-seleccion"><x-icono nombre="cart" />Selecciona una orden de compra</p>
            @endif
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/busqueda-dinamica.js') }}?v={{ filemtime(public_path('js/busqueda-dinamica.js')) }}"></script>
    <script src="{{ asset('js/bandeja-documentos.js') }}?v={{ filemtime(public_path('js/bandeja-documentos.js')) }}"></script>
    <script src="{{ asset('js/compartir-pdf.js') }}?v={{ filemtime(public_path('js/compartir-pdf.js')) }}"></script>
@endpush
