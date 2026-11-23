@extends('layouts.app')

@section('title', 'Configuración · '.config('app.name'))

{{-- Ancha (042, regla 34): los bloques ponen sus campos en columnas. --}}
@section('contenido-clase', 'contenido-ancho')

@section('content')
    <h1>Configuración</h1>

    @include('documentos._mensajes')

    {{-- Pestañas (042). El servidor manda todas las secciones visibles; el
         script solo esconde las no elegidas cuando ya cargó (adición 2). --}}
    <div class="config-pestanas" data-pestanas>
        @if (count($pestanas) > 1)
            @include('configuracion._pestanas')
        @endif

        {{-- Tres pestañas (046, regla 1). Cada bloque lleva su ancla
             (#emisor, #datos-bancarios, #horario, #mensajes,
             #respuestas-rapidas): el script abre la pestaña que lo contiene. --}}
        <div id="negocio" class="config-panel">
            @can('editar-emisor')
                @include('configuracion._emisor')
                @include('configuracion._datos-bancarios')
                @include('configuracion._horario')
            @endcan

            <form method="POST" action="{{ route('configuracion.update') }}" id="mensajes">
                @csrf
                @method('PUT')

                @foreach ($claves as $clave)
                    @include('configuracion._mensaje', ['clave' => $clave, 'valor' => $valores[$clave->value]])
                @endforeach

                <div class="acciones">
                    <x-boton icono="save">Guardar mensajes</x-boton>
                </div>
            </form>
        </div>

        <div id="whatsapp" class="config-panel">
            @can('editar-emisor')
                @include('configuracion._whatsapp')
            @endcan
            @include('configuracion._respuestas-rapidas')
        </div>

        @can('editar-emisor')
            <div id="agentes" class="config-panel">
                @include('configuracion._agentes')
            </div>
        @endcan
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/configuracion-pestanas.js') }}?v={{ filemtime(public_path('js/configuracion-pestanas.js')) }}"></script>
    <script src="{{ asset('js/indicaciones-agente.js') }}?v={{ filemtime(public_path('js/indicaciones-agente.js')) }}"></script>
@endpush
