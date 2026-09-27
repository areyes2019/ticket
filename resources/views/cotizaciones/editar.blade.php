@extends('layouts.app')

@section('title', 'Editar '.$cotizacion->folio_formateado.' · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@section('content')
    <h1>Editar cotización {{ $cotizacion->folio_formateado }}</h1>

    @include('cotizaciones._formulario', ['accion' => route('cotizaciones.update', $cotizacion)])
@endsection
