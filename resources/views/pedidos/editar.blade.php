@extends('layouts.app')

@section('title', 'Editar '.$pedido->folio_formateado.' · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@section('content')
    <h1>Editar pedido {{ $pedido->folio_formateado }}</h1>

    @include('pedidos._formulario', ['accion' => route('pedidos.update', $pedido)])
@endsection
