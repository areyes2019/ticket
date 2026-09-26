@extends('layouts.app')

@section('title', 'Nuevo catálogo · '.config('app.name'))

@section('content')
    <x-card titulo="Nuevo catálogo" :nivel="1">
        @include('catalogos._formulario', ['accion' => route('catalogos.store')])
    </x-card>
@endsection
