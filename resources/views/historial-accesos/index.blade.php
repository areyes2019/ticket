@extends('layouts.app')

@section('title', 'Historial de accesos · '.config('app.name'))

@section('content')
    <h1>Historial de accesos</h1>

    <x-card class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Fecha y hora</th>
                    <th>Correo</th>
                    <th>Usuario</th>
                    <th>Resultado</th>
                    <th>IP</th>
                    <th>Navegador</th>
                    <th>Dispositivo</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($intentos as $intento)
                    <tr>
                        <td>{{ $intento->created_at->format('d/m/Y H:i:s') }}</td>
                        <td>{{ $intento->email }}</td>
                        <td>{{ $intento->user?->name ?? '—' }}</td>
                        <td><span class="etiqueta etiqueta-{{ $intento->resultado->value }}">{{ $intento->resultado->etiqueta() }}</span></td>
                        <td>{{ $intento->ip_address ?? '—' }}</td>
                        <td>{{ $intento->navegador ?? '—' }}</td>
                        <td>{{ $intento->dispositivo ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">Todavía no hay intentos de inicio de sesión.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    <x-paginacion :paginador="$intentos" />
@endsection
