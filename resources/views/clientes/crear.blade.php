@extends('layouts.app')

@section('title', 'Nuevo cliente · '.config('app.name'))

@section('content')
    <h1>Nuevo cliente</h1>

    @include('clientes._formulario', ['accion' => route('clientes.store')])
@endsection
