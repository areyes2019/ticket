@extends('layouts.app')

@section('title', 'Nueva factura · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@section('content')
    <h1>Nueva factura</h1>

    @include('facturas._formulario', ['accion' => route('facturas.store'), 'factura' => null])
@endsection
