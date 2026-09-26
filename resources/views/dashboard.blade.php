@extends('layouts.app')

@section('title', 'Dashboard · '.config('app.name'))

@section('content')
    @if (session('status'))
        <x-alerta tipo="exito">{{ session('status') }}</x-alerta>
    @endif

    <h1>Dashboard</h1>

    <x-card>
        <p>Hola, <strong>{{ auth()->user()->name }}</strong>. Iniciaste sesión correctamente.</p>
    </x-card>
@endsection
