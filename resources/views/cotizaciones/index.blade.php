@extends('layouts.app')

@section('title', 'Cotizaciones · '.config('app.name'))
@section('contenido-clase', 'contenido-bandeja')

@section('content')
    <h1 class="solo-lectores">Cotizaciones</h1>

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
    <x-alerta tipo="error" hidden data-vista-previa-error>No se pudo abrir la cotización. Intenta de nuevo.</x-alerta>
    <x-alerta tipo="exito" hidden data-timbrado-aviso-exito></x-alerta>
    <x-alerta tipo="error" hidden data-timbrado-aviso-error></x-alerta>

    {{-- Carpeta y etiqueta viajan ocultas: los enlaces de la izquierda las
         cambian (data-busqueda-sincronizar). El buscador se asocia con form="…". --}}
    <form id="filtros-cotizaciones" method="GET" action="{{ route('cotizaciones.index') }}" data-busqueda-dinamica="{{ route('cotizaciones.buscar') }}" hidden>
        <input type="hidden" name="periodo" value="{{ $parametros['periodo'] ?? '' }}" data-busqueda-sincronizar>
        <input type="hidden" name="estado" value="{{ $estado }}" data-busqueda-sincronizar>
    </form>

    <div class="bandeja" data-bandeja-documentos data-parametro="cotizacion" data-sin-seleccion="Selecciona una cotización">
        @include('cotizaciones._carpetas')

        <section class="bandeja-lista" aria-label="Lista de cotizaciones" data-busqueda-tabla>
            <x-bandeja.encabezado-lista texto="Buscar por folio, cliente o RFC" nombre="q" :valor="$texto" formulario="filtros-cotizaciones" />

            @include('cotizaciones._filas')
            @include('cotizaciones._paginacion')
        </section>

        <section class="bandeja-visor bandeja-visor-documento" aria-label="Cotización abierta" data-visor-documento>
            @if ($abierta)
                @include('cotizaciones._vista-previa', ['cotizacion' => $abierta])
            @else
                <p class="bandeja-sin-seleccion"><x-icono nombre="file-earmark-text" />Selecciona una cotización</p>
            @endif
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/busqueda-dinamica.js') }}"></script>
    <script src="{{ asset('js/bandeja-documentos.js') }}"></script>
    <script src="{{ asset('js/compartir-pdf.js') }}"></script>
    <script src="{{ asset('js/timbrar-cotizacion.js') }}"></script>
@endpush
