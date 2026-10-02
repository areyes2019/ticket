@extends('layouts.app')

@section('title', 'Editar orden de trabajo '.$pedido->folio_formateado.' · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@section('content')
    <h1>Editar orden de trabajo · {{ $pedido->folio_formateado }}</h1>

    @include('ordenes-trabajo._formulario', ['accion' => route('pedidos.orden-trabajo.update', $pedido)])
@endsection
