@extends('layouts.app')

@section('title', 'Nueva respuesta rápida · '.config('app.name'))

@section('content')
    <x-card titulo="Nueva respuesta rápida" :nivel="1">
        @include('configuracion.respuestas-rapidas._formulario', ['accion' => route('configuracion.respuestas-rapidas.store')])
    </x-card>
@endsection
