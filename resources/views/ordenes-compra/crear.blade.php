@extends('layouts.app')

@section('title', 'Nueva orden de compra · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@section('content')
    <h1>Nueva orden de compra</h1>

    @include('ordenes-compra._formulario', ['accion' => route('ordenes-compra.store')])
@endsection
