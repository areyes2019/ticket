@extends('layouts.app')

@section('title', 'Orden de trabajo '.$pedido->folio_formateado.' · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@php
    $siguiente = $orden->estado->siguiente();
    $sinColor = $orden->lineasSinColor();
@endphp

@section('content')
    <div class="encabezado">
        <h1>
            Orden de trabajo · {{ $pedido->folio_formateado }}
            <span @class(['etiqueta', $orden->estado->claseEtiqueta()])>{{ $orden->estado->etiqueta() }}</span>
        </h1>
        <x-boton :href="route('pedidos.show', $pedido)" variante="secundario" icono="arrow-left">Venta</x-boton>
    </div>

    @include('documentos._mensajes')

    @if ($sinColor->isNotEmpty() && $orden->esEditable())
        <x-alerta tipo="advertencia">
            Falta el color de tinta de: {{ $sinColor->pluck('descripcion')->join(', ') }}. Edita la orden para completarla.
        </x-alerta>
    @endif

    <div class="detalle-documento">
        <div class="detalle-acciones">
            @if ($siguiente !== null && $orden->puedeAvanzar())
                <form method="POST" action="{{ route('pedidos.orden-trabajo.avanzar', $pedido) }}">
                    @csrf
                    <x-boton icono="arrow-right-circle" data-enviar-una-vez
                        data-confirmar="¿Pasar la orden de {{ $pedido->folio_formateado }} a {{ $siguiente->etiqueta() }}? No se puede regresar.">Pasar a {{ $siguiente->etiqueta() }}</x-boton>
                </form>
            @endif

            @if ($mensajeListo !== null)
                <x-boton href="https://wa.me/?text={{ rawurlencode($mensajeListo) }}" variante="secundario" icono="bell" target="_blank" rel="noopener">Avisar que está listo</x-boton>
            @endif

            @can('editarOrdenTrabajo', $pedido)
                <x-boton :href="route('pedidos.orden-trabajo.edit', $pedido)" variante="secundario" icono="pencil">Editar</x-boton>
            @endcan

            <x-boton :href="route('pedidos.orden-trabajo.imprimir', $pedido)" variante="secundario" icono="printer" target="_blank">Imprimir</x-boton>
        </div>

        <div class="detalle-principal">
            @include('ordenes-trabajo._detalle')
        </div>
    </div>
@endsection
