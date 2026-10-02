{{-- La orden como hoja de papel, con el mismo contenido que el PDF. Espera proveedor y lineas cargados. --}}
@php
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
    $proveedor = $orden->proveedor;
@endphp

<article class="hoja" aria-label="Orden de compra {{ $orden->folio_formateado }}">
    <header class="hoja-encabezado">
        <p class="hoja-negocio">{{ config('app.name') }}</p>
        <p class="hoja-folio">
            <strong>Orden de compra {{ $orden->folio_formateado }}</strong><br>
            <span class="hoja-suave">{{ $orden->created_at->setTimezone(config('app.zona_negocio'))->format('d/m/Y') }}</span>
        </p>
    </header>

    <dl class="hoja-cliente">
        <div><dt>Proveedor</dt><dd><strong>{{ $proveedor->nombre_comercial }}</strong></dd></div>
        @if ($proveedor->rfc)
            <div><dt>RFC</dt><dd>{{ $proveedor->rfc }}</dd></div>
        @endif
        @if ($proveedor->nombre_contacto)
            <div><dt>Contacto</dt><dd>{{ $proveedor->nombre_contacto }}</dd></div>
        @endif
        @if ($proveedor->correo)
            <div><dt>Correo</dt><dd>{{ $proveedor->correo }}</dd></div>
        @endif
        @if ($proveedor->telefono)
            <div><dt>Teléfono</dt><dd>{{ $proveedor->telefono }}</dd></div>
        @endif
        @if ($orden->fecha_entrega_esperada)
            <div><dt>Entrega esperada</dt><dd>{{ $orden->fecha_entrega_esperada->format('d/m/Y') }}</dd></div>
        @endif
    </dl>

    <div class="hoja-lineas">
        <table>
            <thead>
                <tr>
                    <th class="numero">Cant.</th>
                    <th>Descripción</th>
                    <th>Modelo</th>
                    <th class="numero">Costo unitario</th>
                    <th class="numero">Descuento</th>
                    <th class="numero">Importe</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($orden->lineas as $linea)
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
        <div><dt>Subtotal</dt><dd>{{ $pesos($orden->subtotal) }}</dd></div>
        @if ((float) $orden->total_descuento > 0)
            <div><dt>Descuento</dt><dd>−{{ $pesos($orden->total_descuento) }}</dd></div>
        @endif
        <div><dt>IVA 16% (acreditable)</dt><dd>{{ $pesos($orden->total_iva_16) }}</dd></div>
        <div class="hoja-total"><dt>Total</dt><dd>{{ $pesos($orden->total) }}</dd></div>
    </dl>

    @if ($orden->observaciones)
        <p><strong>Observaciones:</strong> {!! nl2br(e($orden->observaciones)) !!}</p>
    @endif
</article>
