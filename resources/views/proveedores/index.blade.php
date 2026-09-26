@extends('layouts.app')

@section('title', 'Proveedores · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@section('content')
    <div class="encabezado">
        <h1>Proveedores</h1>
        <x-boton :href="route('proveedores.create')" icono="plus-lg">Nuevo proveedor</x-boton>
    </div>

    @include('proveedores._mensajes')

    <form method="GET" action="{{ route('proveedores.index') }}" class="buscador">
        <x-campo nombre="buscar" etiqueta="Buscar por nombre comercial o de contacto" tipo="search" :valor="$buscar" />
        <x-boton icono="search">Buscar</x-boton>
        @if ($buscar !== '')
            <x-boton :href="route('proveedores.index')" variante="secundario" icono="x-lg">Limpiar</x-boton>
        @endif
    </form>

    <x-card class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Nom. Comercial</th>
                    <th>Contacto</th>
                    <th>Correo</th>
                    <th>Teléfono</th>
                    <th>RFC</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($proveedores as $proveedor)
                    <tr>
                        <td>{{ $proveedor->nombre_comercial }}</td>
                        <td>{{ $proveedor->nombre_contacto ?? '—' }}</td>
                        <td>{{ $proveedor->correo ?? '—' }}</td>
                        <td>{{ $proveedor->telefono ?? '—' }}</td>
                        <td>{{ $proveedor->rfc ?? '—' }}</td>
                        <td>
                            <div class="acciones">
                                <x-boton :href="route('proveedores.edit', $proveedor)" variante="suave" icono="pencil" title="Editar" descripcion="Editar {{ $proveedor->nombre_comercial }}" />

                                <form method="POST" action="{{ route('proveedores.destroy', $proveedor) }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-boton variante="secundario" icono="trash" title="Eliminar" descripcion="Eliminar {{ $proveedor->nombre_comercial }}" data-confirmar="¿Eliminar este proveedor?" />
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            {{ $buscar !== '' ? 'Ningún proveedor coincide con la búsqueda.' : 'Todavía no tienes proveedores registrados.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    <x-paginacion :paginador="$proveedores" />
@endsection
