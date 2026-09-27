@extends('layouts.app')

@section('title', 'Nueva cotización · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@section('content')
    <h1>Nueva cotización</h1>

    @include('cotizaciones._formulario', ['accion' => route('cotizaciones.store')])
@endsection
