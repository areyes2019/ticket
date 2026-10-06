@php
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
    $zona = config('app.zona_negocio');
    $cliente = $cotizacion->cliente;
@endphp

@extends('layouts.pdf', [
    'titulo' => 'COTIZACIÓN',
    'tituloArchivo' => 'Cotización '.$cotizacion->folio_formateado,
    'folio' => $cotizacion->folio_formateado,
    'notaPie' => 'Este documento no es un comprobante fiscal (CFDI).',
])

@section('meta')
    <span class="suave">Fecha</span> {{ $cotizacion->created_at->setTimezone($zona)->format('d/m/Y') }}
@endsection

@if ($datosBancarios !== [])
    @section('encabezado-extra')
        <div class="bancos">
            <div class="titulo-seccion">Datos bancarios</div>
            @foreach ($datosBancarios as $banco)
                <div class="banco">
                    <table>
                        <tr>
                            @if ($banco['logo'])
                                <td class="icono-banco"><img src="{{ $banco['logo'] }}" alt="" style="height: {{ App\Models\Cotizacion::ALTO_LOGO_BANCO_MM }}mm; width: {{ $banco['logo_ancho_mm'] }}mm;"></td>
                            @endif
                            <td><strong>{{ $banco['nombre_banco'] }}</strong></td>
                        </tr>
                    </table>
                    @if (filled($banco['beneficiario']))
                        {{ $banco['beneficiario'] }}<br>
                    @endif
                    @if (filled($banco['numero_cuenta']))
                        Cta: {{ $banco['numero_cuenta'] }}<br>
                    @endif
                    @if (filled($banco['tarjeta']))
                        Tarjeta: {{ $banco['tarjeta'] }}<br>
                    @endif
                    @if (filled($banco['clabe']))
                        CLABE: {{ $banco['clabe'] }}<br>
                    @endif
                </div>
            @endforeach
        </div>
    @endsection
@endif

@section('emisor')
    <x-pdf.emisor :nombre="$emisor->nombre" :rfc="$emisor->rfc" :regimen="$emisor->regimen_fiscal"
        :domicilio="$emisor->domicilio" :correo="$emisor->correo" :telefono="$emisor->telefono"
        :sitio-web="$emisor->sitio_web" :whatsapp="$emisor->whatsapp" />
@endsection

@section('contraparte')
    <div class="rotulo">Cliente</div>
    <div class="nombre">{{ $cliente->razon_social }}</div>
    RFC {{ $cliente->rfc }}<br>
    Régimen <x-pdf.clave-sat :clave="$cliente->regimen_fiscal" enum="App\Enums\RegimenFiscal" /><br>
    Código postal fiscal {{ $cliente->codigo_postal_fiscal }}<br>
    @if ($cliente->direccion_comercial)
        {{ $cliente->direccion_comercial }}<br>
    @endif
    @if ($cliente->correo)
        {{ $cliente->correo }}<br>
    @endif
    @if ($cliente->telefono)
        Tel. {{ $cliente->telefono }}<br>
    @endif
@endsection

@section('conceptos')
    <x-pdf.conceptos :lineas="$cotizacion->lineas" />
@endsection

@section('totales')
    <table class="totales">
        <tr><td>Subtotal</td><td class="numero">{{ $pesos($cotizacion->subtotal) }}</td></tr>
        @if ((float) $cotizacion->total_descuento > 0)
            <tr><td>Descuento</td><td class="numero">−{{ $pesos($cotizacion->total_descuento) }}</td></tr>
        @endif
        <tr><td>IVA 16%</td><td class="numero">{{ $pesos($cotizacion->total_iva_16) }}</td></tr>
        <tr class="total"><td>Total</td><td class="numero">{{ $pesos($cotizacion->total) }} MXN</td></tr>
        @if ($cotizacion->pagos->isNotEmpty())
            <tr><td>Pagado</td><td class="numero">{{ $pesos($cotizacion->totalPagado()) }}</td></tr>
            <tr><td><strong>Saldo pendiente</strong></td><td class="numero"><strong>{{ $pesos($cotizacion->saldoPendiente()) }}</strong></td></tr>
        @endif
    </table>
@endsection
