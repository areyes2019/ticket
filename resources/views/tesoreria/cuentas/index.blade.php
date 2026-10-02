@extends('layouts.app')

@section('title', 'Cuentas · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@php
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
    $conMovimientos = session('cuenta_con_movimientos') ? $cuentas->firstWhere('id', session('cuenta_con_movimientos')) : null;
@endphp

@section('content')
    <div class="encabezado">
        <h1>Contabilidad</h1>
        <x-boton :href="route('tesoreria.cuentas.create')" icono="plus-lg">Nueva cuenta</x-boton>
    </div>

    @include('tesoreria._pestanas')
    @include('tesoreria._mensajes')

    @if ($conMovimientos?->activa)
        <form method="POST" action="{{ route('tesoreria.cuentas.activa', $conMovimientos) }}" class="acciones">
            @csrf
            @method('PATCH')
            <p class="ayuda">Puedes desactivar la cuenta {{ $conMovimientos->nombre }}: conserva su historial y deja de recibir movimientos.</p>
            <x-boton variante="secundario" icono="slash-circle">Desactivar cuenta</x-boton>
        </form>
    @endif

    <form method="GET" action="{{ route('tesoreria.cuentas.index') }}" class="buscador">
        <x-campo nombre="buscar" etiqueta="Buscar por nombre" tipo="search" :valor="$buscar" />
        <x-campo nombre="activa" etiqueta="Estado" tipo="select" :opciones="['1' => 'Activas', '0' => 'Inactivas']" vacia="Todas" :valor="$activa" />
        <x-boton icono="search">Buscar</x-boton>
        @if ($buscar !== '' || $activa !== '')
            <x-boton :href="route('tesoreria.cuentas.index')" variante="secundario" icono="x-lg">Limpiar</x-boton>
        @endif
    </form>

    <x-card class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Cuenta</th>
                    <th>Tipo</th>
                    <th class="numero">Saldo inicial</th>
                    <th class="numero">Saldo actual</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($cuentas as $cuenta)
                    <tr>
                        <td><span class="celda-truncada" title="{{ $cuenta->nombre }}">{{ $cuenta->nombre }}</span></td>
                        <td>{{ $cuenta->tipo->etiqueta() }}</td>
                        <td class="numero">{{ $pesos($cuenta->saldo_inicial) }}</td>
                        <td class="numero">{{ $pesos($cuenta->saldo_actual) }}</td>
                        <td>
                            <span @class(['etiqueta', 'etiqueta-activa' => $cuenta->activa, 'etiqueta-inactiva' => ! $cuenta->activa])>{{ $cuenta->activa ? 'Activa' : 'Inactiva' }}</span>
                        </td>
                        <td>
                            <div class="acciones">
                                <x-boton :href="route('tesoreria.cuentas.edit', $cuenta)" variante="suave" icono="pencil" title="Editar" descripcion="Editar {{ $cuenta->nombre }}" />

                                <form method="POST" action="{{ route('tesoreria.cuentas.activa', $cuenta) }}">
                                    @csrf
                                    @method('PATCH')
                                    @if ($cuenta->activa)
                                        <x-boton variante="suave" icono="slash-circle" title="Desactivar" descripcion="Desactivar {{ $cuenta->nombre }}" />
                                    @else
                                        <x-boton variante="suave" icono="check-circle" title="Activar" descripcion="Activar {{ $cuenta->nombre }}" />
                                    @endif
                                </form>

                                <form method="POST" action="{{ route('tesoreria.cuentas.destroy', $cuenta) }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-boton variante="secundario" icono="trash" title="Eliminar" descripcion="Eliminar {{ $cuenta->nombre }}" data-confirmar="¿Eliminar la cuenta {{ $cuenta->nombre }}? El borrado es definitivo." />
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            {{ $buscar !== '' || $activa !== '' ? 'Ninguna cuenta coincide con la búsqueda.' : 'Todavía no tienes cuentas. Crea la primera para registrar movimientos.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    <x-paginacion :paginador="$cuentas" />
@endsection
