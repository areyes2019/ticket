@extends('layouts.app')

@section('title', 'Saldos · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@php
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
@endphp

@section('content')
    <div class="encabezado">
        <h1>Contabilidad</h1>
    </div>

    @include('tesoreria._pestanas')

    <x-card class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Cuenta</th>
                    <th>Tipo</th>
                    <th>Estado</th>
                    <th class="numero">Saldo actual</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($cuentas as $cuenta)
                    <tr>
                        <td>
                            <a href="{{ route('tesoreria.movimientos.index', ['cuenta_id' => $cuenta->id]) }}" title="Ver sus movimientos">{{ $cuenta->nombre }}</a>
                        </td>
                        <td>{{ $cuenta->tipo->etiqueta() }}</td>
                        <td>
                            <span @class(['etiqueta', 'etiqueta-activa' => $cuenta->activa, 'etiqueta-inactiva' => ! $cuenta->activa])>{{ $cuenta->activa ? 'Activa' : 'Inactiva' }}</span>
                        </td>
                        <td class="numero">{{ $pesos($cuenta->saldo_actual) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">
                            Todavía no tienes cuentas. <a href="{{ route('tesoreria.cuentas.create') }}">Crear una cuenta</a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
            @if ($cuentas->isNotEmpty())
                <tfoot>
                    <tr class="fila-total">
                        <th colspan="3">Total global</th>
                        <td class="numero" data-total-global>{{ $pesos($total) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </x-card>
@endsection
