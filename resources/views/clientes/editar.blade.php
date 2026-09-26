@extends('layouts.app')

@section('title', 'Editar cliente · '.config('app.name'))

@section('content')
    <h1>Editar cliente</h1>

    @include('clientes._formulario', ['accion' => route('clientes.update', $cliente)])
@endsection
