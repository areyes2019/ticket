@extends('layouts.app')

@section('title', 'Catálogos · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@section('content')
    <div class="encabezado">
        <h1>Catálogos</h1>
        <x-boton :href="route('catalogos.create')" icono="plus-lg">Nuevo catálogo</x-boton>
    </div>

    @include('catalogos._mensajes')

    <form method="GET" action="{{ route('catalogos.index') }}" class="buscador">
        <x-campo nombre="buscar" etiqueta="Buscar por catálogo o proveedor" tipo="search" :valor="$buscar" />
        <x-boton icono="search">Buscar</x-boton>
        @if ($buscar !== '')
            <x-boton :href="route('catalogos.index')" variante="secundario" icono="x-lg">Limpiar</x-boton>
        @endif
    </form>

    <x-card class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Catálogo</th>
                    <th>Proveedor</th>
                    <th>Descuento</th>
                    <th>Utilidad</th>
                    <th>Artículos</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($catalogos as $catalogo)
                    <tr>
                        <td><span class="celda-truncada" title="{{ $catalogo->nombre }}">{{ $catalogo->nombre }}</span></td>
                        <td><span class="celda-truncada" title="{{ $catalogo->proveedor->nombre_comercial }}">{{ $catalogo->proveedor->nombre_comercial }}</span></td>
                        <td class="numero">{{ $catalogo->descuento_texto }}</td>
                        <td class="numero">{{ $catalogo->utilidad_texto }}</td>
                        <td class="numero">{{ $catalogo->articulos_count }}</td>
                        <td>
                            <div class="acciones">
                                <x-boton :href="route('catalogos.edit', $catalogo)" variante="suave" icono="pencil" title="Editar" descripcion="Editar {{ $catalogo->nombre }}" />

                                <form method="POST" action="{{ route('catalogos.destroy', $catalogo) }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-boton variante="secundario" icono="trash" title="Eliminar" descripcion="Eliminar {{ $catalogo->nombre }}" data-confirmar="¿Eliminar este catálogo?" />
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            {{ $buscar !== '' ? 'Ningún catálogo coincide con la búsqueda.' : 'Todavía no tienes catálogos registrados.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    <x-paginacion :paginador="$catalogos" />
@endsection
