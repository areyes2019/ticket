@php
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
    $zona = config('app.zona_negocio');
    $cancelada = $factura->estado === App\Enums\EstadoFactura::Cancelada;
@endphp

@extends('layouts.pdf', [
    'titulo' => 'FACTURA',
    'tituloArchivo' => 'Factura '.$factura->folioVisible(),
    'folio' => $factura->folioVisible(),
    'notaPie' => 'Este documento es una representación impresa de un CFDI.',
])

@section('marca-agua')
    @if ($cancelada)
        <div class="marca-agua">CANCELADA</div>
    @endif
@endsection

@section('meta')
    <span class="suave">Folio interno</span> {{ $factura->folio_formateado }}<br>
    Tipo {{ App\Models\Factura::TIPO_COMPROBANTE }} – Ingreso · CFDI {{ $factura->version_comprobante ?? '4.0' }}<br>
    <span class="suave">Fecha de timbrado</span> {{ $factura->fecha_timbrado?->setTimezone($zona)->format('d/m/Y H:i:s') }}
@endsection

@section('emisor')
    {{-- Lo fiscal es la copia del timbrado; el contacto, de Configuración. --}}
    <x-pdf.emisor :nombre="$emisor['razon_social'] ?? null" :rfc="$emisor['rfc'] ?? null" :regimen="$emisor['regimen_fiscal'] ?? null"
        :domicilio="$emisorContacto->domicilio" :correo="$emisorContacto->correo" :telefono="$emisorContacto->telefono">
        Lugar de expedición (CP) {{ $factura->lugar_expedicion ?? '—' }}<br>
        Estado <span class="{{ $cancelada ? 'cancelada' : 'vigente' }}">{{ $cancelada ? 'Cancelada' : 'Vigente' }}</span>
    </x-pdf.emisor>
@endsection

@section('contraparte')
    <div class="rotulo">Receptor</div>
    <div class="nombre">{{ $receptor['razon_social'] }}</div>
    RFC {{ $receptor['rfc'] }}<br>
    Régimen <x-pdf.clave-sat :clave="$receptor['regimen_fiscal']" enum="App\Enums\RegimenFiscal" /><br>
    Domicilio fiscal (CP) {{ $receptor['codigo_postal'] }}<br>
    @if ($receptor['correo'])
        {{ $receptor['correo'] }}<br>
    @endif
    Uso de CFDI {{ $factura->uso_cfdi->value }} – {{ $factura->uso_cfdi->descripcion() }}<br>
    Forma de pago {{ $factura->forma_pago->value }} – {{ $factura->forma_pago->descripcion() }}<br>
    Método de pago {{ $factura->metodo_pago->value }} – {{ $factura->metodo_pago->etiqueta() }}<br>
    Moneda {{ App\Models\Factura::MONEDA }}
@endsection

@section('conceptos')
    <x-pdf.conceptos :lineas="$factura->lineas" :con-unidad="true" :descuento-cfdi="true" />
@endsection

@section('totales')
    <table class="totales">
        <tr><td>Subtotal</td><td class="numero">{{ $pesos($factura->subtotal) }}</td></tr>
        @if ((float) $factura->total_descuento > 0)
            <tr><td>Descuento</td><td class="numero">−{{ $pesos($factura->total_descuento) }}</td></tr>
        @endif
        <tr><td>IVA trasladado 16%</td><td class="numero">{{ $pesos($factura->total_iva_16) }}</td></tr>
        <tr class="total"><td>Total</td><td class="numero">{{ $pesos($factura->total) }} {{ App\Models\Factura::MONEDA }}</td></tr>
    </table>
@endsection

@section('extras')
    <p class="derecha"><em>{{ $importeEnLetra }}</em></p>

    @if ($factura->uuid_fiscal)
        <table class="seccion" style="width: 100%; page-break-inside: avoid;">
            <tr>
                <td style="width: 23%; vertical-align: top; padding-right: 6pt;">
                    @if ($qr)
                        <img src="{{ $qr }}" alt="Código QR de verificación del SAT" style="width: 30mm; height: 30mm;">
                    @else
                        <div class="titulo-seccion">Verificación SAT</div>
                        <x-pdf.mono-box :texto="$urlVerificacion" :corte="32" />
                    @endif
                    <div class="titulo-seccion">Folio fiscal (UUID)</div>
                    <div style="font-size: 7pt;">{{ $factura->uuid_fiscal }}</div>
                    <div class="titulo-seccion">No. de certificado del SAT</div>
                    <div style="font-size: 7pt;">{{ $factura->no_certificado_sat }}</div>
                </td>
                <td style="width: 77%; vertical-align: top;">
                    <div class="titulo-seccion">Sello digital del CFDI</div>
                    <x-pdf.mono-box :texto="$factura->sello_cfdi" />
                    <div class="titulo-seccion">Sello del SAT</div>
                    <x-pdf.mono-box :texto="$factura->sello_sat" />
                    <div class="titulo-seccion">Cadena original del complemento de certificación digital del SAT</div>
                    <x-pdf.mono-box :texto="$factura->cadena_original_sat" />
                </td>
            </tr>
        </table>
    @endif
@endsection
