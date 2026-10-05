@extends('layouts.app')

@section('title', 'Configuración · '.config('app.name'))

@section('content')
    <h1>Configuración</h1>

    @include('documentos._mensajes')

    @can('editar-emisor')
        @include('configuracion._emisor')
        @include('configuracion._datos-bancarios')
    @endcan

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
@endsection
