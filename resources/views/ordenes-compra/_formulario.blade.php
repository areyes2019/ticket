@include('documentos._mensajes')

@php
    // Lo heredan las filas (documentos._linea): la orden captura costo, no precio de venta.
    $etiquetaPrecio = 'Costo unitario sin IVA';
@endphp

@if ($orden?->estado === App\Enums\EstadoOrdenCompra::Enviada)
    <x-alerta tipo="advertencia">Esta orden ya se envió. Al guardar regresa a borrador y tendrás que reenviarla para que el proveedor vea los cambios.</x-alerta>
@endif

@if ($proveedores === [])
    <x-alerta tipo="advertencia">Todavía no tienes proveedores. <a href="{{ route('proveedores.create') }}">Registra uno</a> para poder hacerle una orden de compra.</x-alerta>
@endif

{{-- data-sugerencias pide el costo; documento-lineas.js le agrega el proveedor elegido. --}}
<form method="POST" action="{{ $accion }}" data-documento-lineas data-sugerencias="{{ route('articulos.sugerencias', ['precio' => 'costo']) }}">
    @csrf
    @isset($orden)
        @method('PUT')
    @endisset

    <x-card titulo="Proveedor">
        <x-campo nombre="proveedor_id" etiqueta="Proveedor" tipo="select" :opciones="$proveedores" :valor="$orden?->proveedor_id" vacia="Selecciona un proveedor" required data-proveedor-orden />
    </x-card>

    <x-card titulo="Líneas">
        {{-- El buscador y "Agregar línea libre" necesitan JavaScript; sin él se capturan líneas libres en las filas vacías. --}}
        <div class="barra-lineas" data-controles-lineas hidden>
            {{-- Sin name propio que importe: el servidor ignora "buscar_articulo". --}}
            <div class="buscador-articulos">
                <x-campo nombre="buscar_articulo" etiqueta="Agregar artículo del proveedor" tipo="search" placeholder="Nombre o modelo" autocomplete="off" data-buscar-articulo
                    data-sin-proveedor="Elige primero el proveedor" />
            </div>
            <x-boton tipo="button" variante="secundario" icono="plus-lg" data-agregar-linea-libre>Agregar línea libre</x-boton>
        </div>

        <div class="tabla-contenedor">
            <table class="tabla tabla-lineas">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Cantidad</th>
                        <th>Descripción</th>
                        <th>Modelo</th>
                        <th>Costo unitario</th>
                        <th>Descuento</th>
                        <th>IVA</th>
                        <th class="numero">Importe</th>
                        <th><span class="solo-lectores">Quitar</span></th>
                    </tr>
                </thead>
                <tbody data-lineas>
                    @foreach ($lineas as $i => $linea)
                        @include('documentos._linea', ['i' => $i, 'linea' => $linea])
                    @endforeach
                    @for ($extra = count($lineas); $extra < count($lineas) + 3; $extra++)
                        @include('documentos._linea', ['i' => $extra, 'linea' => [], 'vacia' => true])
                    @endfor
                </tbody>
            </table>
        </div>
        <p class="ayuda" data-sin-lineas hidden>Agrega artículos del proveedor con el buscador o una línea libre (fletes, maniobras…).</p>

        <template id="plantilla-linea">
            @include('documentos._linea', ['i' => '__i__', 'linea' => []])
        </template>
    </x-card>

    <x-card titulo="Totales">
        <div class="descuento-global">
            <x-campo nombre="descuento_global_tipo" etiqueta="Descuento global" tipo="select" :opciones="$tiposDescuento" :valor="$orden?->descuento_global_tipo?->value" vacia="Sin descuento" />
            <x-campo nombre="descuento_global_valor" etiqueta="Valor del descuento" tipo="number" :valor="$orden?->descuento_global_valor" min="0" step="0.01" inputmode="decimal" />
        </div>

        <dl class="resumen-precio" data-resumen-totales>
            <div><dt>Subtotal</dt><dd><output data-total="subtotal">{{ $orden ? '$'.number_format((float) $orden->subtotal, 2) : '—' }}</output></dd></div>
            <div><dt>Descuento</dt><dd><output data-total="total_descuento">{{ $orden ? '−$'.number_format((float) $orden->total_descuento, 2) : '—' }}</output></dd></div>
            <div><dt>IVA 16%</dt><dd><output data-total="total_iva_16">{{ $orden ? '$'.number_format((float) $orden->total_iva_16, 2) : '—' }}</output></dd></div>
            <div class="resumen-total"><dt>Total</dt><dd><output data-total="total">{{ $orden ? '$'.number_format((float) $orden->total, 2) : '—' }}</output></dd></div>
        </dl>
        <p class="ayuda">Estimado mientras capturas: el total que cuenta lo calcula el sistema al guardar.</p>
    </x-card>

    <x-card titulo="Entrega">
        <x-campo nombre="fecha_entrega_esperada" etiqueta="Fecha de entrega esperada" tipo="date" :valor="$orden?->fecha_entrega_esperada?->toDateString()" ayuda="Opcional. Se imprime en la orden." />
        <x-campo nombre="observaciones" etiqueta="Observaciones" tipo="textarea" :valor="$orden?->observaciones" rows="3" :maxlength="App\Http\Requests\OrdenCompraRequest::MAX_OBSERVACIONES"
            ayuda="Opcional. Se imprimen en la orden para el proveedor (condiciones de entrega, referencias…)." />
    </x-card>

    <div class="acciones">
        <x-boton icono="save">Guardar</x-boton>
        <x-boton :href="$orden ? route('ordenes-compra.show', $orden) : route('ordenes-compra.index')" variante="secundario" icono="x-lg">Cancelar</x-boton>
    </div>
</form>

@include('documentos._aviso-duplicado', ['documento' => 'la orden de compra'])

@push('scripts')
    <script src="{{ asset('js/totales-documento.js') }}?v={{ filemtime(public_path('js/totales-documento.js')) }}"></script>
    <script src="{{ asset('js/documento-lineas.js') }}?v={{ filemtime(public_path('js/documento-lineas.js')) }}"></script>
@endpush
