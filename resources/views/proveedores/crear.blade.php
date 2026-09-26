@extends('layouts.app')

@section('title', 'Nuevo proveedor · '.config('app.name'))

@section('content')
    <x-card titulo="Nuevo proveedor" :nivel="1">
        @include('proveedores._formulario', ['accion' => route('proveedores.store')])
    </x-card>
@endsection
