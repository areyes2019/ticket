@extends('layouts.app')

@section('title', 'Editar respuesta rápida · '.config('app.name'))

@section('content')
    <x-card titulo="Editar respuesta rápida" :nivel="1">
        @include('configuracion.respuestas-rapidas._formulario', ['accion' => route('configuracion.respuestas-rapidas.update', $respuesta)])
    </x-card>
@endsection
