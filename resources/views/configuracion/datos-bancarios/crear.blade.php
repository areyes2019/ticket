@extends('layouts.app')

@section('title', 'Agregar banco · '.config('app.name'))

@section('content')
    <x-card titulo="Agregar banco" :nivel="1">
        @include('configuracion.datos-bancarios._formulario', ['accion' => route('configuracion.datos-bancarios.store')])
    </x-card>
@endsection
