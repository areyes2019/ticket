@extends('layouts.app')

@section('title', $articulo->modelo.' · Existencias · '.config('app.name'))
@section('contenido-clase', 'contenido-ancho')

@php
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
@endphp

@section('content')
    <div class="encabezado">
        <h1>{{ $articulo->modelo }}</h1>
        <div class="acciones">
            @if ($fila)
                <x-boton href="#dialogo-ajuste" icono="sliders" data-abrir-dialogo>Ajustar</x-boton>
                <x-boton href="#dialogo-parametros" variante="secundario" icono="bar-chart-steps" data-abrir-dialogo>Mínimo y máximo</x-boton>
                <form method="POST" action="{{ route('existencias.destroy', $articulo) }}">
                    @csrf
                    @method('DELETE')
                    <x-boton variante="secundario" icono="box-arrow-up"
                        data-confirmar="Dejarás de llevar el inventario de {{ $articulo->modelo }}. Su historial se conserva.{{ $fila->existencia > 0 ? ' Todavía tiene '.$fila->existencia.' piezas registradas.' : '' }}">Quitar de existencias</x-boton>
                </form>
            @else
                <x-boton href="#dialogo-ajuste" icono="box-arrow-in-down" data-abrir-dialogo>Pasar a existencias</x-boton>
            @endif
            <x-boton :href="route('existencias.index')" variante="secundario" icono="arrow-left">Volver a existencias</x-boton>
        </div>
    </div>

    @include('existencias._mensajes')

    <x-card titulo="Artículo">
        <dl class="datos">
            <div><dt>Nombre</dt><dd><a href="{{ route('articulos.edit', $articulo) }}">{{ $articulo->nombre }}</a></dd></div>
            <div><dt>Catálogo</dt><dd>{{ $articulo->catalogo->nombre }}</dd></div>
            <div><dt>Proveedor</dt><dd>{{ $articulo->proveedor->nombre_comercial }}</dd></div>
            <div><dt>Costo sin IVA</dt><dd>{{ $pesos($articulo->costo_con_descuento) }}</dd></div>
            <div><dt>Precio de venta sin IVA</dt><dd>{{ $pesos($articulo->precio_unitario_sin_iva) }}</dd></div>
        </dl>
    </x-card>

    @if ($fila)
        <x-card titulo="Existencias">
            @if ($fila->porPedir())
                <x-alerta tipo="advertencia">Está por pedir: se sugiere comprar {{ number_format($fila->cantidadSugerida()) }} piezas.</x-alerta>
            @endif
            <dl class="datos">
                <div><dt>Existencia</dt><dd>{{ number_format($fila->existencia) }}</dd></div>
                @if ($fila->faltante_pendiente > 0)
                    <div><dt>Faltante pendiente</dt><dd class="monto-negativo">{{ number_format($fila->faltante_pendiente) }}</dd></div>
                @endif
                <div><dt>Mínimo</dt><dd>{{ $fila->minimo > 0 ? number_format($fila->minimo) : 'Sin aviso' }}</dd></div>
                <div><dt>Máximo</dt><dd>{{ $fila->maximo === null ? '—' : number_format($fila->maximo) }}</dd></div>
                <div><dt>Dinero invertido</dt><dd>{{ $pesos($fila->invertido()) }}</dd></div>
                <div><dt>Beneficio potencial</dt><dd>{{ $pesos($fila->beneficio()) }}</dd></div>
            </dl>
        </x-card>
    @else
        <x-alerta tipo="advertencia">
            Este artículo no está en existencias.
            @if ($tuvoFila)
                Al agregarlo se recuperan sus mínimos anteriores.
            @endif
        </x-alerta>
    @endif

    <x-card titulo="Movimientos" class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Tipo</th>
                    <th>Motivo</th>
                    <th class="numero">Cantidad</th>
                    <th class="numero">Existencia</th>
                    <th class="numero">Faltante</th>
                    <th>Nota</th>
                    <th>Origen</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($movimientos as $movimiento)
                    @php
                        $origen = $movimiento->documentoOrigen();
                    @endphp
                    <tr @class(['fila-automatica' => $origen !== null])>
                        <td>{{ $movimiento->created_at->timezone(config('app.zona_negocio'))->format('d/m/Y H:i') }}</td>
                        <td>{{ $movimiento->tipo->etiqueta() }}</td>
                        <td>{{ $movimiento->motivo->etiqueta() }}</td>
                        <td class="numero">{{ number_format($movimiento->cantidad) }}</td>
                        <td class="numero">{{ number_format($movimiento->existencia_resultante) }}</td>
                        <td class="numero">{{ $movimiento->faltante_resultante > 0 ? number_format($movimiento->faltante_resultante) : '' }}</td>
                        <td><span class="celda-truncada" title="{{ $movimiento->nota }}">{{ $movimiento->nota }}</span></td>
                        <td>
                            @if ($origen !== null)
                                <a href="{{ $origen['url'] }}">{{ $origen['etiqueta'] }}</a>
                            @else
                                Manual
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">Este artículo todavía no tiene movimientos de inventario.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    <x-paginacion :paginador="$movimientos" />

    @include('existencias._dialogo-ajuste')

    @if ($fila)
        @include('existencias._dialogo-parametros')
    @endif
@endsection
