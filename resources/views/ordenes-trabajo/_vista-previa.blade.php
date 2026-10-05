{{-- Visor del dashboard (spec 020, corrección 1): acciones, estado y el cuerpo
     de la orden. Se pinta con la página o llega por AJAX
     (pedidos.orden-trabajo.vista-previa) al elegir una fila. "Avanzar" regresa
     al dashboard con la orden abierta (origen=dashboard); "Entregado", sin
     ella, porque la orden entregada sale de la lista (022, corrección 1). --}}
@php
    $siguiente = $orden->estado->siguiente();
    $sinColor = $orden->lineasSinColor();
@endphp

<div class="bandeja-acciones">
    <x-boton variante="suave" tipo="button" icono="arrow-left" class="bandeja-volver" data-volver>Volver</x-boton>
    @if ($siguiente !== null && $orden->puedeAvanzar())
        <form method="POST" action="{{ route('pedidos.orden-trabajo.avanzar', $pedido) }}">
            @csrf
            <input type="hidden" name="origen" value="dashboard">
            <x-boton icono="arrow-right-circle" data-enviar-una-vez
                data-confirmar="¿Pasar la orden de {{ $pedido->folio_formateado }} a {{ $siguiente->etiqueta() }}? No se puede regresar.">Pasar a {{ $siguiente->etiqueta() }}</x-boton>
        </form>
    @endif
    @if (in_array($orden->estado, [App\Enums\EstadoOrdenTrabajo::Terminado, App\Enums\EstadoOrdenTrabajo::Entregado], true))
        @include('pedidos._entregar', ['origen' => 'dashboard'])
    @endif
    @if ($mensajeListo !== null)
        <x-boton href="https://wa.me/?text={{ rawurlencode($mensajeListo) }}" variante="secundario" icono="bell" descripcion="Avisar que está listo" title="Avisar que está listo" target="_blank" rel="noopener" />
    @endif
    @can('editarOrdenTrabajo', $pedido)
        <x-boton :href="route('pedidos.orden-trabajo.edit', $pedido)" variante="secundario" icono="pencil" descripcion="Editar" title="Editar" />
    @endcan
    <x-boton :href="route('pedidos.orden-trabajo.imprimir', $pedido)" variante="secundario" icono="printer" descripcion="Imprimir" title="Imprimir" target="_blank" />
    <x-boton :href="route('pedidos.show', $pedido)" variante="secundario" icono="bag" descripcion="Ver venta" title="Ver venta" />
    <x-boton :href="route('pedidos.orden-trabajo.show', $pedido)" variante="suave" icono="box-arrow-up-right" class="bandeja-abrir-detalle">Abrir</x-boton>
</div>

<div class="bandeja-documento" data-vista-previa-de="{{ $orden->id }}" data-documento="ot">
    <h2 class="bandeja-documento-titulo">Orden de trabajo · {{ $pedido->folio_formateado }}</h2>
    <p class="bandeja-documento-estado">
        <span @class(['etiqueta', $orden->estado->claseEtiqueta()])>{{ $orden->estado->etiqueta() }}</span>
    </p>

    @if ($sinColor->isNotEmpty() && $orden->esEditable())
        <x-alerta tipo="advertencia">
            Falta el color de tinta de: {{ $sinColor->pluck('descripcion')->join(', ') }}. Edita la orden para completarla.
        </x-alerta>
    @endif

    @if ($pedido->lineasDeTrabajo()->isEmpty())
        <x-alerta tipo="advertencia" data-orden-sin-produccion>Esta orden ya no tiene artículos de producción.</x-alerta>
    @endif

    @include('ordenes-trabajo._detalle')
</div>
