@extends('layouts.app')

@section('title', 'Nuevo pedido · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@section('content')
    <h1>Nuevo pedido</h1>

    @include('pedidos._formulario', ['pedido' => null, 'accion' => route('pedidos.store')])
@endsection
