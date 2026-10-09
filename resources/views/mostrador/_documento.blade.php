{{--
    El cuerpo de un documento en el detalle del mostrador (034): encabezado,
    renglones en tarjetas (no la tabla ancha del escritorio) y totales.
    Parámetros: $documento (Cotizacion o Factura con cliente y lineas
    cargados), $folio, $conPagos (la cotización: pagado y saldo).
--}}
@php
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
@endphp

<div class="mostrador-revision">
    <p class="mostrador-documento-encabezado">
        <span class="mostrador-revision-nombre">{{ $folio }}</span>
        <span class="etiqueta {{ $documento->estado->claseEtiqueta() }}" data-estado-documento>{{ $documento->estado->etiqueta() }}</span>
    </p>
    <p><strong>{{ $documento->cliente->razon_social }}</strong></p>
    <p class="mostrador-rfc">{{ $documento->cliente->rfc }}</p>
    <p class="mostrador-ficha-dato">{{ $documento->created_at->setTimezone(config('app.zona_negocio'))->format('d/m/Y') }}</p>
</div>

<ul class="mostrador-carrito" aria-label="Renglones">
    @foreach ($documento->lineas as $linea)
        <li class="mostrador-renglon">
            <span class="mostrador-renglon-datos">
                <strong>{{ $linea->descripcion }}</strong>
                <span class="mostrador-ficha-dato">
                    {{ $linea->modelo ? $linea->modelo.' · ' : '' }}{{ $linea->cantidad }} × {{ $pesos($linea->precio_unitario) }}@if ($linea->descuentoTexto() !== '') · desc. {{ $linea->descuentoTexto() }}@endif
                </span>
            </span>
            <span></span>
            <span class="mostrador-renglon-importe">{{ $pesos($linea->importe) }}</span>
        </li>
    @endforeach
</ul>

<dl class="mostrador-totales">
    <div><dt>Subtotal</dt><dd>{{ $pesos($documento->subtotal) }}</dd></div>
    @if ((float) $documento->total_descuento > 0)
        <div><dt>Descuento</dt><dd>−{{ $pesos($documento->total_descuento) }}</dd></div>
    @endif
    <div><dt>IVA 16%</dt><dd>{{ $pesos($documento->total_iva_16) }}</dd></div>
    <div class="mostrador-totales-total"><dt>Total</dt><dd>{{ $pesos($documento->total) }}</dd></div>
    @if (($conPagos ?? false) && $documento->pagos->isNotEmpty())
        <div><dt>Pagado</dt><dd>{{ $pesos($documento->totalPagado()) }}</dd></div>
        <div class="mostrador-totales-saldo"><dt>Saldo pendiente</dt><dd>{{ $pesos($documento->saldoPendiente()) }}</dd></div>
    @endif
</dl>
