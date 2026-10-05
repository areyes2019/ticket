@php
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
    $zona = config('app.zona_negocio');
    $proveedor = $orden->proveedor;
@endphp

@extends('layouts.pdf', [
    'titulo' => 'ORDEN DE COMPRA',
    'tituloArchivo' => 'Orden de compra '.$orden->folio_formateado,
    'folio' => $orden->folio_formateado,
    'notaPie' => 'Este documento no es un comprobante fiscal (CFDI).',
])

@section('meta')
    <span class="suave">Fecha</span> {{ $orden->created_at->setTimezone($zona)->format('d/m/Y') }}
    @if ($orden->fecha_entrega_esperada)
        <br><span class="suave">Entrega esperada</span> <strong>{{ $orden->fecha_entrega_esperada->format('d/m/Y') }}</strong>
    @endif
@endsection

@section('emisor')
    <x-pdf.emisor :nombre="$emisor->nombre" :rfc="$emisor->rfc" :regimen="$emisor->regimen_fiscal"
        :domicilio="$emisor->domicilio" :correo="$emisor->correo" :telefono="$emisor->telefono" />
@endsection

@section('contraparte')
    <div class="rotulo">Proveedor</div>
    <div class="nombre">{{ $proveedor->nombre_comercial }}</div>
    @if ($proveedor->rfc)
        RFC {{ $proveedor->rfc }}<br>
    @endif
    @if ($proveedor->nombre_contacto)
        Contacto {{ $proveedor->nombre_contacto }}<br>
    @endif
    @if ($proveedor->correo)
        {{ $proveedor->correo }}<br>
    @endif
    @if ($proveedor->telefono)
        Tel. {{ $proveedor->telefono }}<br>
    @endif
@endsection

@section('conceptos')
    <x-pdf.conceptos :lineas="$orden->lineas" etiqueta-precio="Costo unitario" />
@endsection

@section('totales')
    <table class="totales">
        <tr><td>Subtotal</td><td class="numero">{{ $pesos($orden->subtotal) }}</td></tr>
        @if ((float) $orden->total_descuento > 0)
            <tr><td>Descuento</td><td class="numero">−{{ $pesos($orden->total_descuento) }}</td></tr>
        @endif
        <tr><td>IVA 16% (acreditable)</td><td class="numero">{{ $pesos($orden->total_iva_16) }}</td></tr>
        <tr class="total"><td>Total</td><td class="numero">{{ $pesos($orden->total) }} MXN</td></tr>
    </table>
@endsection

@section('extras')
    @if ($orden->observaciones)
        <div class="seccion">
            <div class="titulo-seccion">Observaciones</div>
            {!! nl2br(e($orden->observaciones)) !!}
        </div>
    @endif
@endsection
