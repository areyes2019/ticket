@include('documentos._mensajes')

@if ($pedido && $pedido->tienePagos())
    <x-alerta tipo="advertencia">Este pedido ya tiene pagos por ${{ number_format((float) $pedido->totalPagado(), 2) }}: el total no puede quedar por debajo.</x-alerta>
@endif

<form method="POST" action="{{ $accion }}" data-documento-lineas data-sugerencias="{{ route('articulos.sugerencias') }}">
    @csrf
    @isset($pedido)
        @method('PUT')
    @endisset

    <x-card titulo="Cliente">
        <div class="campos-cliente-pedido" data-cliente-pedido data-sugerir="{{ route('pedidos.cliente-por-telefono') }}" @if ($pedido) data-excepto="{{ $pedido->id }}" @endif>
            <x-campo nombre="cliente_telefono" etiqueta="Teléfono" tipo="tel" :valor="$pedido?->telefono_legible" inputmode="tel" autocomplete="off" maxlength="20" required data-telefono-cliente />
            <x-campo nombre="cliente_nombre" etiqueta="Nombre" :valor="$pedido?->cliente_nombre" maxlength="150" autocomplete="off" required />
            <x-campo nombre="cliente_correo" etiqueta="Correo (opcional)" tipo="email" :valor="$pedido?->cliente_correo" maxlength="255" autocomplete="off" />
        </div>
        {{-- La llena pedido-cliente.js si el teléfono ya compró antes. --}}
        <x-alerta tipo="advertencia" hidden data-sugerencia-cliente>
            Este teléfono compró antes como <strong data-sugerencia-nombre></strong><span data-sugerencia-correo></span>.
            <x-boton tipo="button" variante="secundario" icono="person-check" data-usar-sugerencia>Usar esos datos</x-boton>
        </x-alerta>
    </x-card>

    <x-card titulo="Líneas">
        {{-- El buscador y "Agregar línea libre" necesitan JavaScript; sin él se capturan líneas libres en las filas vacías. --}}
        <div class="barra-lineas" data-controles-lineas hidden>
            {{-- Sin name propio que importe: el servidor ignora "buscar_articulo". --}}
            <div class="buscador-articulos">
                <x-campo nombre="buscar_articulo" etiqueta="Agregar artículo" tipo="search" placeholder="Nombre o modelo" autocomplete="off" data-buscar-articulo />
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
                        <th>Precio unitario</th>
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
        <p class="ayuda" data-sin-lineas hidden>Agrega artículos con el buscador o una línea libre.</p>
        <p class="ayuda">Los artículos del catálogo deben tener existencia en bodega; una línea libre no mueve inventario.</p>

        <template id="plantilla-linea">
            @include('documentos._linea', ['i' => '__i__', 'linea' => []])
        </template>
    </x-card>

    <x-card titulo="Totales">
        <div class="descuento-global">
            <x-campo nombre="descuento_global_tipo" etiqueta="Descuento global" tipo="select" :opciones="$tiposDescuento" :valor="$pedido?->descuento_global_tipo?->value" vacia="Sin descuento" />
            <x-campo nombre="descuento_global_valor" etiqueta="Valor del descuento" tipo="number" :valor="$pedido?->descuento_global_valor" min="0" step="0.01" inputmode="decimal" />
        </div>

        <dl class="resumen-precio" data-resumen-totales>
            <div><dt>Subtotal</dt><dd><output data-total="subtotal">{{ $pedido ? '$'.number_format((float) $pedido->subtotal, 2) : '—' }}</output></dd></div>
            <div><dt>Descuento</dt><dd><output data-total="total_descuento">{{ $pedido ? '−$'.number_format((float) $pedido->total_descuento, 2) : '—' }}</output></dd></div>
            <div><dt>IVA 16%</dt><dd><output data-total="total_iva_16">{{ $pedido ? '$'.number_format((float) $pedido->total_iva_16, 2) : '—' }}</output></dd></div>
            <div class="resumen-total"><dt>Total</dt><dd><output data-total="total">{{ $pedido ? '$'.number_format((float) $pedido->total, 2) : '—' }}</output></dd></div>
        </dl>
        <p class="ayuda">Estimado mientras capturas: el total que cuenta lo calcula el sistema al guardar.</p>
    </x-card>

    <div class="acciones">
        <x-boton icono="save" data-enviar-una-vez>Guardar</x-boton>
        <x-boton :href="$pedido ? route('pedidos.show', $pedido) : route('pedidos.index')" variante="secundario" icono="x-lg">Cancelar</x-boton>
    </div>
</form>

@include('documentos._aviso-duplicado', ['documento' => 'la venta'])

@push('scripts')
    <script src="{{ asset('js/totales-documento.js') }}"></script>
    <script src="{{ asset('js/documento-lineas.js') }}"></script>
    <script src="{{ asset('js/pedido-cliente.js') }}"></script>
@endpush
