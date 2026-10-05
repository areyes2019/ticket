@extends('layouts.app')

@section('title', 'Editar banco · '.config('app.name'))

@section('content')
    <x-card titulo="Editar banco" :nivel="1">
        @include('configuracion.datos-bancarios._formulario', ['accion' => route('configuracion.datos-bancarios.update', $dato)])
    </x-card>
@endsection
