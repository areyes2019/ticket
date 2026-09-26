@extends('layouts.app')

@section('title', 'Nuevo artículo · '.config('app.name'))

@section('content')
    <h1>Nuevo artículo</h1>

    @include('articulos._formulario', ['accion' => route('articulos.store')])
@endsection
