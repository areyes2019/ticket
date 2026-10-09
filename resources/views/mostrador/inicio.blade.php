@extends('layouts.mostrador')

@section('barra', 'inicio')

@section('content')
    {{-- Tres accesos fijos, sin cifras ni pendientes (033). Toda la superficie es tocable. --}}
    <nav class="mostrador-accesos" aria-label="Accesos del mostrador">
        <a href="{{ route('mostrador.factura') }}" class="mostrador-acceso">
            <x-icono nombre="receipt" />
            <span>Generar factura</span>
        </a>
        <a href="{{ route('mostrador.cotizacion') }}" class="mostrador-acceso">
            <x-icono nombre="file-earmark-text" />
            <span>Generar cotización</span>
        </a>
        <a href="{{ route('mostrador.venta') }}" class="mostrador-acceso">
            <x-icono nombre="ticket-perforated" />
            <span>Venta al público</span>
        </a>
    </nav>

    <form method="POST" action="{{ route('logout') }}" class="mostrador-salir">
        @csrf
        <x-boton variante="suave" icono="box-arrow-right">Cerrar sesión</x-boton>
    </form>
@endsection
