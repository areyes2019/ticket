@extends('layouts.app')

@section('title', 'Editar proveedor · '.config('app.name'))

@section('content')
    <x-card titulo="Editar proveedor" :nivel="1">
        @include('proveedores._formulario', ['accion' => route('proveedores.update', $proveedor)])
    </x-card>
@endsection
