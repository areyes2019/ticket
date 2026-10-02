@extends('layouts.app')

@section('title', 'Editar cuenta · '.config('app.name'))

@section('content')
    <x-card titulo="Editar cuenta" :nivel="1">
        @include('tesoreria.cuentas._formulario', ['accion' => route('tesoreria.cuentas.update', $cuenta)])
    </x-card>
@endsection
