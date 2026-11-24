@extends('layouts.app')

@section('title', 'Agregar a Artículos IA · '.config('app.name'))

@section('content')
    <div class="encabezado">
        <h1>Agregar a Artículos IA</h1>
        <x-boton :href="route('articulos-ia.index')" variante="secundario" icono="arrow-left">Volver a Artículos IA</x-boton>
    </div>

    <form method="GET" action="{{ route('articulos-ia.agregar') }}" class="buscador">
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
                                <form method="POST" action="{{ route('articulos-ia.store', $articulo) }}">
                                    @csrf
                                    <x-boton variante="suave" icono="robot">Agregar a Artículos IA</x-boton>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">Ningún artículo fuera de la lista coincide con la búsqueda.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-card>
        @if ($articulos->count() === \App\Http\Controllers\ArticuloIaController::LIMITE_AGREGAR)
            <p class="ayuda">Se muestran los primeros {{ \App\Http\Controllers\ArticuloIaController::LIMITE_AGREGAR }}. Escribe más del nombre o modelo para afinar.</p>
        @endif
    @else
        <p class="ayuda">Busca un artículo de tu catálogo general que todavía no esté en Artículos IA.</p>
    @endif
@endsection
