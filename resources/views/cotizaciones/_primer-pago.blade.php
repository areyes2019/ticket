{{-- Lo que agrega el primer pago a las ventanas de pago (029): el aviso de lo
     que va a nacer y, si nace la venta, su contacto (precargado del cliente; se
     guarda en la venta, no en el catálogo) y los faltantes de existencia.
     Parámetros: $cotizacion, $destino (DestinoCobro|null), $prefijo. --}}
@if ($destino !== null)
    <x-alerta tipo="info" data-aviso-destino="{{ $destino->value }}">{{ $destino->aviso() }}</x-alerta>

    @if ($destino->creaVenta())
        @php
            $cliente = $cotizacion->cliente;
            $faltantes = $cotizacion->faltantesAlCrearVenta();
        @endphp

        @if ($faltantes !== [])
            <x-alerta tipo="advertencia" data-faltantes-venta>
                <p>No hay existencia suficiente; quedará faltante de:</p>
                <ul>
                    @foreach ($faltantes as $faltante)
                        <li>{{ $faltante }}</li>
                    @endforeach
                </ul>
            </x-alerta>
        @endif

        <fieldset class="datos-venta">
            <legend>Datos de la venta</legend>
            <x-campo nombre="cliente_nombre" :id="$prefijo.'-cliente-nombre'" etiqueta="Nombre en la venta" :valor="$cliente->nombre_contacto ?: $cliente->razon_social" maxlength="150" required />
            <x-campo nombre="cliente_telefono" :id="$prefijo.'-cliente-telefono'" etiqueta="Teléfono" tipo="tel" :valor="substr((string) preg_replace('/\D/', '', (string) $cliente->telefono), -10)" inputmode="tel" required
                ayuda="Lo llevan el ticket y la etiqueta. 10 dígitos." />
            <x-campo nombre="cliente_correo" :id="$prefijo.'-cliente-correo'" etiqueta="Correo (opcional)" tipo="email" :valor="$cliente->correo" />
        </fieldset>
    @endif
@endif
