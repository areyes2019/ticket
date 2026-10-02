@extends('layouts.app')

@section('title', 'Agregar a existencias · '.config('app.name'))

@section('content')
    <div class="encabezado">
        <h1>Agregar artículo a existencias</h1>
        <x-boton :href="route('existencias.index')" variante="secundario" icono="arrow-left">Volver a existencias</x-boton>
    </div>

    <form method="GET" action="{{ route('existencias.agregar') }}" class="buscador">
        <x-campo nombre="q" etiqueta="Buscar en el catálogo por nombre o modelo" tipo="search" :valor="$texto" autofocus autocomplete="off" />
        <x-boton icono="search">Buscar</x-boton>
    </form>

    @if ($texto !== '')
        <x-card class="tabla-contenedor">
            <table class="tabla">
                <thead>
                    <tr>
                        <th>Modelo</th>
                        <th>Nombre</th>
                        <th>Catálogo</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($articulos as $articulo)
                        <tr>
                            <td>{{ $articulo->modelo }}</td>
                            <td><span class="celda-truncada" title="{{ $articulo->nombre }}">{{ $articulo->nombre }}</span></td>
                            <td><span class="celda-truncada" title="{{ $articulo->catalogo->nombre }}">{{ $articulo->catalogo->nombre }}</span></td>
                            <td>
                                <x-boton :href="route('existencias.show', $articulo)" variante="suave" icono="box-arrow-in-down">Pasar a existencias</x-boton>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">Ningún artículo fuera de existencias coincide con la búsqueda.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-card>
        @if ($articulos->count() === 20)
            <p class="ayuda">Se muestran los primeros 20. Escribe más del nombre o modelo para afinar.</p>
        @endif
    @else
        <p class="ayuda">Busca un artículo de tu catálogo general que todavía no esté en existencias.</p>
    @endif
@endsection
