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

        @can('editar-emisor')
            <div id="emisor" class="config-panel">
                @include('configuracion._emisor')
            </div>
            <div id="datos-bancarios" class="config-panel">
                @include('configuracion._datos-bancarios')
            </div>
            <div id="whatsapp" class="config-panel">
                @include('configuracion._whatsapp')
            </div>
        @endcan

        <div id="respuestas-rapidas" class="config-panel">
            @include('configuracion._respuestas-rapidas')
        </div>

        <div id="mensajes" class="config-panel">
            <form method="POST" action="{{ route('configuracion.update') }}">
                @csrf
                @method('PUT')

                @foreach ($claves as $clave)
                    @include('configuracion._mensaje', ['clave' => $clave, 'valor' => $valores[$clave->value]])
                @endforeach

                <div class="acciones">
                    <x-boton icono="save">Guardar</x-boton>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/configuracion-pestanas.js') }}?v={{ filemtime(public_path('js/configuracion-pestanas.js')) }}"></script>
    <script src="{{ asset('js/indicaciones-agente.js') }}?v={{ filemtime(public_path('js/indicaciones-agente.js')) }}"></script>
@endpush
