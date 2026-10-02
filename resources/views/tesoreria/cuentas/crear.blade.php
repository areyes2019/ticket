@extends('layouts.app')

@section('title', 'Nueva cuenta · '.config('app.name'))

@section('content')
    <x-card titulo="Nueva cuenta" :nivel="1">
        @include('tesoreria.cuentas._formulario', ['accion' => route('tesoreria.cuentas.store')])
    </x-card>
@endsection
