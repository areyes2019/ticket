@include('documentos._mensajes')

@if ($cotizacion?->estado === App\Enums\EstadoCotizacion::Enviada)
    <x-alerta tipo="advertencia">Esta cotización ya se envió. Al guardar regresa a borrador y tendrás que reenviarla para que el cliente vea los cambios.</x-alerta>
@endif

@if ($clientes === [])
    <x-alerta tipo="advertencia">Todavía no tienes clientes. <a href="{{ route('clientes.create') }}">Registra uno</a> para poder cotizar.</x-alerta>
@endif

<form method="POST" action="{{ $accion }}" data-documento-lineas data-sugerencias="{{ route('articulos.sugerencias') }}">
    @csrf
    @isset($cotizacion)
        @method('PUT')
    @endisset

    <x-card titulo="Cliente">
        @if ($cotizacion?->venta)
            {{-- El tipo de cliente decidió la venta (029): ya no se cambia. El select deshabilitado no viaja; viaja el oculto. --}}
            <x-campo nombre="cliente_id" etiqueta="Cliente" tipo="select" :opciones="$clientes" :valor="$cotizacion->cliente_id" vacia="Selecciona un cliente" disabled
                ayuda="El cliente ya no se cambia: la cotización tiene venta." />
            <input type="hidden" name="cliente_id" value="{{ $cotizacion->cliente_id }}">
        @else
            <x-campo nombre="cliente_id" etiqueta="Cliente" tipo="select" :opciones="$clientes" :valor="$cotizacion?->cliente_id" vacia="Selecciona un cliente" required />
        @endif
    </x-card>

    @if ($cotizacion?->ventaQueCobraAqui())
        <x-alerta tipo="info" data-aviso-copia-venta>Los cambios se copian a la venta {{ $cotizacion->venta->folio_formateado }}.</x-alerta>
    @endif

    <x-card titulo="Líneas">
        @include('cotizaciones._aviso-descuento-cliente')
        @include('documentos._aviso-distribuidor', ['clienteInicial' => $cotizacion?->cliente_id, 'excepcion' => true])

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

        <template id="plantilla-linea">
            @include('documentos._linea', ['i' => '__i__', 'linea' => []])
        </template>
    </x-card>

    <x-card titulo="Totales">
        <div class="descuento-global">
            <x-campo nombre="descuento_global_tipo" etiqueta="Descuento global" tipo="select" :opciones="$tiposDescuento" :valor="$cotizacion?->descuento_global_tipo?->value" vacia="Sin descuento" />
            <x-campo nombre="descuento_global_valor" etiqueta="Valor del descuento" tipo="number" :valor="$cotizacion?->descuento_global_valor" min="0" step="0.01" inputmode="decimal" />
        </div>

        <dl class="resumen-precio" data-resumen-totales>
            <div><dt>Subtotal</dt><dd><output data-total="subtotal">{{ $cotizacion ? '$'.number_format((float) $cotizacion->subtotal, 2) : '—' }}</output></dd></div>
            <div><dt>Descuento</dt><dd><output data-total="total_descuento">{{ $cotizacion ? '−$'.number_format((float) $cotizacion->total_descuento, 2) : '—' }}</output></dd></div>
            <div><dt>IVA 16%</dt><dd><output data-total="total_iva_16">{{ $cotizacion ? '$'.number_format((float) $cotizacion->total_iva_16, 2) : '—' }}</output></dd></div>
            <div class="resumen-total"><dt>Total</dt><dd><output data-total="total">{{ $cotizacion ? '$'.number_format((float) $cotizacion->total, 2) : '—' }}</output></dd></div>
        </dl>
        <p class="ayuda">Estimado mientras capturas: el total que cuenta lo calcula el sistema al guardar.</p>
    </x-card>

    <div class="acciones">
        <x-boton icono="save">Guardar</x-boton>
        <x-boton :href="$cotizacion ? route('cotizaciones.show', $cotizacion) : route('cotizaciones.index')" variante="secundario" icono="x-lg">Cancelar</x-boton>
    </div>
</form>

@include('documentos._aviso-duplicado', ['documento' => 'la cotización'])

@push('scripts')
    <script src="{{ asset('js/totales-documento.js') }}?v={{ filemtime(public_path('js/totales-documento.js')) }}"></script>
    <script src="{{ asset('js/documento-lineas.js') }}?v={{ filemtime(public_path('js/documento-lineas.js')) }}"></script>
@endpush
