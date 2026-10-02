@extends('layouts.app')

@section('title', 'Nueva venta · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@section('content')
    <h1>Nueva venta</h1>

    @include('pedidos._formulario', ['pedido' => null, 'accion' => route('pedidos.store')])
@endsection
