@extends('layouts.app')

@section('title', 'Dashboard · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@section('content')
    @if (session('status'))
        <x-alerta tipo="exito">{{ session('status') }}</x-alerta>
    @endif

    <p class="bandeja-demo"><x-icono nombre="info-circle" />Bandeja de demostración: los correos son de ejemplo.</p>

    @php
        $carpetaInicial = 'entrada';
        $primero = collect($correos)->firstWhere('carpeta', $carpetaInicial);
    @endphp

    <div class="bandeja" data-bandeja>
        <x-bandeja.carpetas :carpetas="$carpetas" :etiquetas="$etiquetas" :activa="$carpetaInicial" />

        <section class="bandeja-lista" aria-label="Lista de correos">
            <x-bandeja.encabezado-lista />

            <ul class="bandeja-filas">
                @foreach ($correos as $correo)
                    <x-bandeja.fila-correo :correo="$correo" :etiquetas="$etiquetas"
                                           :visible="$correo['carpeta'] === $carpetaInicial"
                                           :activa="$correo['id'] === $primero['id']" />
                @endforeach
            </ul>

            <p class="bandeja-vacia" data-vacia hidden><x-icono nombre="envelope-open" />Sin correos</p>
        </section>

        <section class="bandeja-visor" aria-label="Correo abierto">
            @foreach ($correos as $correo)
                <x-bandeja.visor-correo :correo="$correo" :etiquetas="$etiquetas" :visible="$correo['id'] === $primero['id']" />
            @endforeach

            <p class="bandeja-sin-seleccion" data-sin-seleccion hidden><x-icono nombre="envelope" />Selecciona un correo para leerlo</p>
        </section>
    </div>

    <x-bandeja.redactar />

    <div class="bandeja-aviso" role="status" data-bandeja-aviso hidden></div>
@endsection

@push('scripts')
    <script src="{{ asset('js/bandeja-correo.js') }}"></script>
@endpush
