@extends('layouts.app')

@section('title', 'WhatsApp · '.config('app.name'))
@section('contenido-clase', 'contenido-bandeja')

@section('content')
    <h1 class="solo-lectores">WhatsApp</h1>

    @include('documentos._mensajes')

    @if ($numero === null)
        <x-alerta tipo="advertencia">
            Todavía no hay un número de WhatsApp conectado.
            @can('editar-emisor')
                <a href="{{ route('configuracion.edit') }}#whatsapp">Conéctalo en Configuración</a>.
            @else
                Pídele al administrador que lo conecte en Configuración.
            @endcan
        </x-alerta>
    @elseif ($gasto !== null && $gasto >= App\Services\Whatsapp\RegistroUso::AVISO_PORCENTAJE)
        {{-- Regla 73. --}}
        <x-alerta tipo="advertencia">
            Los agentes llevan el {{ min($gasto, 100) }} % del tope de gasto del mes.
            @if ($gasto >= 100) Están pausados hasta el mes siguiente o hasta que subas el tope. @endif
        </x-alerta>
    @endif

    <form id="filtros-whatsapp" method="GET" action="{{ $abierta ? route('whatsapp.show', $abierta) : route('whatsapp.index') }}" hidden>
        <input type="hidden" name="carpeta" value="{{ $carpeta }}">
    </form>

    <div @class(['bandeja', 'bandeja-whatsapp', 'bandeja-leyendo' => $abierta]) data-whatsapp
         data-lista="{{ route('whatsapp.lista', ['carpeta' => $carpeta, 'q' => $texto, 'abierta' => $abiertaId]) }}"
         @if ($abierta) data-nuevos="{{ route('whatsapp.nuevos', $abierta) }}" @endif>
        @include('whatsapp._carpetas')

        <section class="bandeja-lista" aria-label="Lista de conversaciones">
            <x-bandeja.encabezado-lista texto="Buscar por nombre, teléfono o mensaje" nombre="q" :valor="$texto" formulario="filtros-whatsapp" />

            <div data-whatsapp-lista>
                @include('whatsapp._lista')
            </div>
        </section>

        <section class="bandeja-visor wa-visor" aria-label="Conversación abierta">
            @if ($abierta)
                @include('whatsapp._conversacion')
            @else
                <p class="bandeja-sin-seleccion"><x-icono nombre="whatsapp" />Selecciona una conversación</p>
            @endif
        </section>
    </div>

    @if ($abierta)
        @include('whatsapp._dialogo-plantilla', ['accion' => route('whatsapp.plantilla', $abierta)])
    @endif
@endsection

@push('scripts')
    <script src="{{ asset('js/whatsapp.js') }}?v={{ filemtime(public_path('js/whatsapp.js')) }}"></script>
@endpush
