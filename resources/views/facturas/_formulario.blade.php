@include('documentos._mensajes')

@if ($factura?->error_timbrado && ! session('error'))
    <x-alerta tipo="error">Último intento de timbrado: {{ $factura->error_timbrado }}</x-alerta>
@endif

@if ($clientes === [])
    <x-alerta tipo="advertencia">Todavía no tienes clientes. <a href="{{ route('clientes.create') }}">Registra uno</a> para poder facturar.</x-alerta>
@endif

<noscript>
    <x-alerta tipo="advertencia">Para agregar artículos a la factura se necesita JavaScript. Sin él solo se pueden corregir las líneas ya capturadas.</x-alerta>
</noscript>

<form method="POST" action="{{ $accion }}" data-documento-lineas data-sin-lineas-libres data-sugerencias="{{ route('articulos.sugerencias') }}" data-formulario-factura>
    @csrf
    @isset($factura)
        @method('PUT')
    @endisset

    <x-card titulo="Cliente y datos fiscales">
        <x-campo nombre="cliente_id" etiqueta="Cliente" tipo="select" :opciones="$clientes" :valor="$factura?->cliente_id" vacia="Selecciona un cliente" required />
        <x-campo nombre="uso_cfdi" etiqueta="Uso de CFDI" tipo="select" :opciones="$usosCfdi" :valor="$factura?->uso_cfdi?->value ?? App\Enums\UsoCfdi::GastosEnGeneral->value" vacia="Selecciona el uso de CFDI" required />
        <x-campo nombre="metodo_pago" etiqueta="Método de pago" tipo="select" :opciones="$metodosPago" :valor="$factura?->metodo_pago?->value ?? App\Enums\MetodoPago::UnaExhibicion->value" vacia="Selecciona el método de pago" required data-metodo-pago />
        <x-campo nombre="forma_pago" etiqueta="Forma de pago" tipo="select" :opciones="$formasPago" :valor="$factura?->forma_pago?->value" vacia="Selecciona la forma de pago" required
            ayuda="Con PPD (pago diferido) la forma de pago es 99 – Por definir." data-forma-pago />
    </x-card>

    <x-card titulo="Líneas">
        <div class="barra-lineas" data-controles-lineas hidden>
            {{-- Sin name propio que importe: el servidor ignora "buscar_articulo". --}}
            <div class="buscador-articulos">
                <x-campo nombre="buscar_articulo" etiqueta="Agregar artículo" tipo="search" placeholder="Nombre o modelo" autocomplete="off" data-buscar-articulo />
            </div>
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
                </tbody>
            </table>
        </div>
        <p class="ayuda" data-sin-lineas @if ($lineas !== []) hidden @endif>Agrega artículos con el buscador. Toda línea de factura viene de tu catálogo de artículos.</p>

        <template id="plantilla-linea">
            @include('documentos._linea', ['i' => '__i__', 'linea' => []])
        </template>
    </x-card>

    <x-card titulo="Totales">
        <div class="descuento-global">
            <x-campo nombre="descuento_global_tipo" etiqueta="Descuento global" tipo="select" :opciones="$tiposDescuento" :valor="$factura?->descuento_global_tipo?->value" vacia="Sin descuento" />
            <x-campo nombre="descuento_global_valor" etiqueta="Valor del descuento" tipo="number" :valor="$factura?->descuento_global_valor" min="0" step="0.01" inputmode="decimal" />
        </div>

        <dl class="resumen-precio" data-resumen-totales>
            <div><dt>Subtotal</dt><dd><output data-total="subtotal">{{ $factura ? '$'.number_format((float) $factura->subtotal, 2) : '—' }}</output></dd></div>
            <div><dt>Descuento</dt><dd><output data-total="total_descuento">{{ $factura ? '−$'.number_format((float) $factura->total_descuento, 2) : '—' }}</output></dd></div>
            <div><dt>IVA 16%</dt><dd><output data-total="total_iva_16">{{ $factura ? '$'.number_format((float) $factura->total_iva_16, 2) : '—' }}</output></dd></div>
            <div class="resumen-total"><dt>Total</dt><dd><output data-total="total">{{ $factura ? '$'.number_format((float) $factura->total, 2) : '—' }}</output></dd></div>
        </dl>
        <p class="ayuda">Estimado mientras capturas: el total que cuenta lo calcula el sistema al guardar.</p>
    </x-card>

    <div class="acciones">
        <x-boton icono="patch-check" data-enviar-una-vez>{{ $factura ? 'Guardar y timbrar' : 'Generar y timbrar' }}</x-boton>
        <x-boton :href="$factura ? route('facturas.show', $factura) : route('facturas.index')" variante="secundario" icono="x-lg">Cancelar</x-boton>
    </div>
</form>

@include('documentos._aviso-duplicado', ['documento' => 'la factura'])

@push('scripts')
    <script src="{{ asset('js/totales-documento.js') }}"></script>
    <script src="{{ asset('js/documento-lineas.js') }}"></script>
    <script src="{{ asset('js/facturas.js') }}"></script>
@endpush
