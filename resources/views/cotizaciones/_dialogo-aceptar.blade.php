{{-- Ventana "Aceptar" (spec 021): crea la venta de la cotización con su cliente,
     líneas y total. Pide el contacto que llevará la venta (ticket y etiqueta),
     precargado del cliente; se guarda en la venta, no en el catálogo.
     Parámetro: $cotizacion. Lleva siempre al detalle de la venta. --}}
@php
    $cliente = $cotizacion->cliente;
    $faltantes = $cotizacion->faltantesAlAceptar();
    $telefono = substr((string) preg_replace('/\D/', '', (string) $cliente->telefono), -10);
    $conErrores = $errors->aceptar->any();
@endphp

<dialog id="dialogo-aceptar" class="ficha dialogo" aria-labelledby="dialogo-aceptar-titulo" @if ($conErrores) data-abrir-al-cargar @endif>
    <form method="POST" action="{{ route('cotizaciones.aceptar', $cotizacion) }}">
        @csrf

        <h2 id="dialogo-aceptar-titulo"><x-icono nombre="check2-circle" />¿Aceptar {{ $cotizacion->folio_formateado }}?</h2>

        <p>Se creará una venta con el mismo cliente, productos y total
            (<strong>${{ number_format((float) $cotizacion->total, 2) }}</strong>). El cobro, el ticket, la entrega y la
            factura siguen en la venta.</p>

        @if ($conErrores)
            <x-alerta tipo="error">
                @foreach ($errors->aceptar->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </x-alerta>
        @endif

        @if ($faltantes !== [])
            <x-alerta tipo="advertencia" data-faltantes-aceptar>
                <p>No hay existencia suficiente; quedará faltante de:</p>
                <ul>
                    @foreach ($faltantes as $faltante)
                        <li>{{ $faltante }}</li>
                    @endforeach
                </ul>
            </x-alerta>
        @endif

        <x-campo nombre="cliente_nombre" id="aceptar-nombre" etiqueta="Nombre en la venta" :valor="$cliente->nombre_contacto ?: $cliente->razon_social" maxlength="150" required />
        <x-campo nombre="cliente_telefono" id="aceptar-telefono" etiqueta="Teléfono" tipo="tel" :valor="$telefono" inputmode="tel" required
            ayuda="Lo llevan el ticket y la etiqueta. 10 dígitos." />
        <x-campo nombre="cliente_correo" id="aceptar-correo" etiqueta="Correo (opcional)" tipo="email" :valor="$cliente->correo" />

        <div class="acciones">
            <x-boton icono="check2-circle" data-enviar-una-vez>Aceptar y crear venta</x-boton>
            <x-boton href="#" variante="secundario" icono="x-lg" data-cerrar-dialogo>Cancelar</x-boton>
        </div>
    </form>
</dialog>
