{{-- Confirmación del timbrado directo (spec 020): la factura nace con el cliente,
     las líneas y los precios de la cotización, y aquí solo se eligen los datos
     fiscales. timbrar-cotizacion.js envía el formulario sin recargar y pone la
     factura en la lista; sin JavaScript, se envía normal y lleva al detalle. --}}
@php
    $avisosPrecio = $cotizacion->avisosDePrecio();
    $lineas = $cotizacion->lineas->count();
@endphp

<dialog id="dialogo-timbrar" class="ficha dialogo dialogo-timbrar" aria-labelledby="dialogo-timbrar-titulo">
    <form method="POST" action="{{ route('cotizaciones.timbrar', $cotizacion) }}" data-timbrar-cotizacion>
        @csrf
        <h2 id="dialogo-timbrar-titulo"><x-icono nombre="receipt" />¿Timbrar la factura de {{ $cotizacion->folio_formateado }}?</h2>

        <dl class="timbrar-resumen">
            <div><dt>Cliente</dt><dd><strong>{{ $cotizacion->cliente->razon_social }}</strong><br><span class="ayuda">{{ $cotizacion->cliente->rfc }}</span></dd></div>
            <div><dt>Líneas</dt><dd>{{ $lineas }} {{ $lineas === 1 ? 'línea' : 'líneas' }}</dd></div>
            <div class="timbrar-resumen-total"><dt>Total</dt><dd>${{ number_format((float) $cotizacion->total, 2) }}</dd></div>
        </dl>

        @if ($avisosPrecio !== [])
            <x-alerta tipo="advertencia">
                <p>Estos precios cambiaron en el catálogo; la factura conserva el de la cotización:</p>
                <ul>
                    @foreach ($avisosPrecio as $aviso)
                        <li>{{ $aviso }}</li>
                    @endforeach
                </ul>
            </x-alerta>
        @endif

        <x-alerta tipo="error" hidden data-timbrar-error></x-alerta>

        <div class="timbrar-campos">
            <x-campo nombre="uso_cfdi" id="timbrar-uso-cfdi" etiqueta="Uso de CFDI" tipo="select"
                     :opciones="App\Enums\UsoCfdi::opcionesFactura()" :valor="App\Enums\UsoCfdi::GastosEnGeneral->value" :vacia="false" required />
            <x-campo nombre="metodo_pago" id="timbrar-metodo-pago" etiqueta="Método de pago" tipo="select"
                     :opciones="App\Enums\MetodoPago::opciones()" :valor="App\Enums\MetodoPago::UnaExhibicion->value" :vacia="false" required />
            <x-campo nombre="forma_pago" id="timbrar-forma-pago" etiqueta="Forma de pago" tipo="select"
                     :opciones="App\Enums\FormaPago::opciones()" vacia="Selecciona la forma de pago" required />
        </div>

        <p class="ayuda">Se emite el CFDI ante el SAT con las líneas y precios de la cotización. Una factura timbrada ya no se edita: solo se cancela.</p>

        <div class="acciones">
            <x-boton icono="receipt" data-timbrar-enviar>Sí, timbrar</x-boton>
            <x-boton :href="route('facturas.create', ['cotizacion' => $cotizacion->id])" variante="suave" icono="pencil-square">Revisar en el formulario</x-boton>
            <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cancelar</x-boton>
        </div>
    </form>
</dialog>
