@extends('layouts.app')

@section('title', 'Editar artículo · '.config('app.name'))

@section('content')
    <h1>Editar artículo</h1>

    @include('articulos._formulario', ['accion' => route('articulos.update', $articulo)])
@endsection
