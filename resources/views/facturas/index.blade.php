@extends('layouts.app')

@section('title', 'Facturas · '.config('app.name'))
@section('contenido-clase', 'contenido-bandeja')

@section('content')
    <h1 class="solo-lectores">Facturas</h1>

    @include('documentos._mensajes')

    <x-alerta tipo="error" hidden data-compartir-error></x-alerta>
    <x-alerta tipo="error" hidden data-busqueda-error>No se pudo realizar la búsqueda. Intenta de nuevo.</x-alerta>
    <x-alerta tipo="error" hidden data-vista-previa-error>No se pudo abrir la factura. Intenta de nuevo.</x-alerta>

    {{-- Carpeta y etiqueta viajan ocultas: los enlaces de la izquierda las
         cambian (data-busqueda-sincronizar). El buscador se asocia con form="…". --}}
    <form id="filtros-facturas" method="GET" action="{{ route('facturas.index') }}" data-busqueda-dinamica="{{ route('facturas.buscar') }}" hidden>
        <input type="hidden" name="periodo" value="{{ $parametros['periodo'] ?? '' }}" data-busqueda-sincronizar>
        <input type="hidden" name="estado" value="{{ $estado }}" data-busqueda-sincronizar>
    </form>

    <div class="bandeja" data-bandeja-documentos data-parametro="factura" data-sin-seleccion="Selecciona una factura">
        @include('facturas._carpetas')

        <section class="bandeja-lista" aria-label="Lista de facturas" data-busqueda-tabla>
            <x-bandeja.encabezado-lista texto="Buscar por folio, cliente, RFC o UUID" nombre="q" :valor="$texto" formulario="filtros-facturas" />

            @include('facturas._filas')
            @include('facturas._paginacion')
        </section>

        <section class="bandeja-visor bandeja-visor-documento" aria-label="Factura abierta" data-visor-documento>
            @if ($abierta)
                @include('facturas._vista-previa', ['factura' => $abierta])
            @else
                <p class="bandeja-sin-seleccion"><x-icono nombre="receipt" />Selecciona una factura</p>
            @endif
        </section>
    </div>

    <dialog id="dialogo-cotizaciones" class="ficha dialogo" aria-labelledby="dialogo-cotizaciones-titulo"
        data-elegir-cotizacion="{{ route('facturas.cotizaciones') }}">
        <h2 id="dialogo-cotizaciones-titulo">Facturar una cotización</h2>
        <form method="GET" action="{{ route('facturas.cotizaciones') }}" data-elegir-cotizacion-buscador>
            <x-campo nombre="q" id="elegir-cotizacion-q" etiqueta="Buscar cotización" tipo="search" placeholder="Folio, cliente o RFC" autocomplete="off" />
        </form>
        <x-alerta tipo="error" hidden data-elegir-cotizacion-error>No se pudieron cargar las cotizaciones. Intenta de nuevo.</x-alerta>
        <div data-elegir-cotizacion-lista aria-live="polite"></div>
        <div class="acciones">
            <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cerrar</x-boton>
        </div>
    </dialog>
@endsection

@push('scripts')
    <script src="{{ asset('js/busqueda-dinamica.js') }}?v={{ filemtime(public_path('js/busqueda-dinamica.js')) }}"></script>
    <script src="{{ asset('js/bandeja-documentos.js') }}?v={{ filemtime(public_path('js/bandeja-documentos.js')) }}"></script>
    <script src="{{ asset('js/compartir-pdf.js') }}?v={{ filemtime(public_path('js/compartir-pdf.js')) }}"></script>
    <script src="{{ asset('js/elegir-cotizacion.js') }}?v={{ filemtime(public_path('js/elegir-cotizacion.js')) }}"></script>
@endpush
