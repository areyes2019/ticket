@extends('layouts.app')

@section('title', 'Corregir '.$factura->folio_formateado.' · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@section('content')
    <h1>Corregir factura {{ $factura->folio_formateado }}</h1>

    @include('facturas._formulario', ['accion' => route('facturas.update', $factura)])
@endsection
