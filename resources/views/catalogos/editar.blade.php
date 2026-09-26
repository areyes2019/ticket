@extends('layouts.app')

@section('title', 'Editar catálogo · '.config('app.name'))

@section('content')
    <x-card titulo="Editar catálogo" :nivel="1">
        @include('catalogos._formulario', ['accion' => route('catalogos.update', $catalogo)])
    </x-card>
@endsection
