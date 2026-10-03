@props(['cotizacion'])

{{-- La cotización como hoja de papel, con el mismo contenido que el PDF. La usan
     el visor de la bandeja y el detalle. Espera cliente, lineas y pagos cargados. --}}
@php
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
    $cliente = $cotizacion->cliente;
@endphp

<article {{ $attributes->class('hoja') }} aria-label="Cotización {{ $cotizacion->folio_formateado }}">
    <header class="hoja-encabezado">
        <p class="hoja-negocio">{{ config('app.name') }}</p>
        <p class="hoja-folio">
            <strong>Cotización {{ $cotizacion->folio_formateado }}</strong><br>
            <span class="hoja-suave">{{ $cotizacion->created_at->setTimezone(config('app.zona_negocio'))->format('d/m/Y') }}</span>
        </p>
    </header>

    <dl class="hoja-cliente">
        <div><dt>Cliente</dt><dd><strong>{{ $cliente->razon_social }}</strong></dd></div>
        <div><dt>RFC</dt><dd>{{ $cliente->rfc }}</dd></div>
        @if ($cliente->correo)
            <div><dt>Correo</dt><dd>{{ $cliente->correo }}</dd></div>
        @endif
        @if ($cliente->telefono)
            <div><dt>Teléfono</dt><dd>{{ $cliente->telefono }}</dd></div>
        @endif
        {{-- Solo en pantalla (023): el PDF que recibe el cliente no lo lleva. "Al cotizar" porque es el congelado. --}}
        @if ($cotizacion->tieneDescuentoCliente())
            <div data-descuento-cliente-al-cotizar><dt>Descuento de cliente al cotizar</dt><dd><strong>{{ App\Models\Cliente::porcentajeTexto($cotizacion->descuento_cliente_porcentaje) }}%</strong></dd></div>
        @endif
    </dl>

    <div class="hoja-lineas">
        <table>
            <thead>
                <tr>
                    <th class="numero">Cant.</th>
                    <th>Descripción</th>
                    <th>Modelo</th>
                    <th class="numero">Precio unitario</th>
                    <th class="numero">Descuento</th>
                    <th class="numero">Importe</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($cotizacion->lineas as $linea)
                    <tr>
                        <td class="numero">{{ $linea->cantidad }}</td>
                        <td>{{ $linea->descripcion }}</td>
                        <td>{{ $linea->modelo }}</td>
                        <td class="numero">{{ $pesos($linea->precio_unitario) }}</td>
                        <td class="numero">{{ $linea->descuentoTexto() }}</td>
                        <td class="numero">{{ $pesos($linea->importe) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <dl class="hoja-totales">
        <div><dt>Subtotal</dt><dd>{{ $pesos($cotizacion->subtotal) }}</dd></div>
        @if ((float) $cotizacion->total_descuento > 0)
            <div><dt>Descuento</dt><dd>−{{ $pesos($cotizacion->total_descuento) }}</dd></div>
        @endif
        <div><dt>IVA 16%</dt><dd>{{ $pesos($cotizacion->total_iva_16) }}</dd></div>
        <div class="hoja-total"><dt>Total</dt><dd>{{ $pesos($cotizacion->total) }}</dd></div>
        @if ($cotizacion->pagos->isNotEmpty())
            <div><dt>Pagado</dt><dd>{{ $pesos($cotizacion->totalPagado()) }}</dd></div>
            <div class="hoja-saldo"><dt>Saldo pendiente</dt><dd>{{ $pesos($cotizacion->saldoPendiente()) }}</dd></div>
        @endif
    </dl>
</article>
