@extends('layouts.app')

@section('title', 'Editar '.$orden->folio_formateado.' · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@section('content')
    <h1>Editar orden de compra {{ $orden->folio_formateado }}</h1>

    @include('ordenes-compra._formulario', ['accion' => route('ordenes-compra.update', $orden)])
@endsection
