@php
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
    $zona = config('app.zona_negocio');
    // Dompdf no parte palabras largas: los sellos (base64 sin espacios) se cortan en renglones.
    $renglones = fn (?string $texto) => implode('<br>', array_map('e', str_split((string) $texto, 100)));
    $regimen = fn (?string $clave) => $clave ? $clave.' – '.(App\Enums\RegimenFiscal::tryFrom($clave)?->descripcion() ?? '') : '—';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Factura {{ $factura->folioVisible() }}</title>
    {{-- Dompdf no lee public/css/app.css: los valores copian los tokens de diseño (003). --}}
    <style>
        @page { margin: 1.3cm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #1f2937; }
        h1 { margin: 0; font-size: 14pt; color: #1e3a5f; }
        .encabezado { width: 100%; border-bottom: 2px solid #1e3a5f; margin-bottom: 8pt; }
        .encabezado td { vertical-align: top; padding-bottom: 6pt; }
        .derecha { text-align: right; }
        .suave { color: #6b7280; }
        .partes { width: 100%; margin-bottom: 8pt; border-collapse: collapse; }
        .partes td { vertical-align: top; width: 50%; padding: 4pt 6pt; background: #f9fafb; }
        .titulo { font-size: 7.5pt; color: #6b7280; text-transform: uppercase; }
        .datos { width: 100%; margin-bottom: 8pt; border-collapse: collapse; }
        .datos td { padding: 1pt 6pt 1pt 0; }
        table.lineas { width: 100%; border-collapse: collapse; }
        table.lineas th { background: #f3f4f6; text-align: left; font-size: 7.5pt; }
        table.lineas th, table.lineas td { padding: 3pt; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .numero, table.lineas th.numero { text-align: right; white-space: nowrap; }
        table.totales { margin-top: 8pt; margin-left: auto; border-collapse: collapse; }
        table.totales td { padding: 2pt 0 2pt 16pt; }
        .total td { font-weight: bold; font-size: 11pt; border-top: 1px solid #1f2937; }
        .letra { margin-top: 4pt; font-style: italic; }
        .sellos { margin-top: 10pt; width: 100%; border-collapse: collapse; }
        .sellos td { vertical-align: top; }
        .sello { font-size: 6.5pt; margin: 0 0 4pt; }
        .leyenda { margin-top: 8pt; font-size: 7pt; color: #6b7280; text-align: center; }
        .marca-agua { position: fixed; top: 38%; left: 8%; font-size: 64pt; color: #fee2e2; transform: rotate(-30deg); z-index: -1; }
    </style>
</head>
<body>
    @if ($factura->estado === App\Enums\EstadoFactura::Cancelada)
        <div class="marca-agua">CANCELADA</div>
    @endif

    <table class="encabezado">
        <tr>
            <td>
                <h1>{{ $emisor['razon_social'] ?? config('app.name') }}</h1>
                @if ($emisor)
                    <span>RFC {{ $emisor['rfc'] }}</span><br>
                    <span class="suave">Régimen {{ $regimen($emisor['regimen_fiscal']) }}</span>
                @endif
            </td>
            <td class="derecha">
                <strong>Factura {{ $factura->folioVisible() }}</strong><br>
                <span class="suave">Folio interno {{ $factura->folio_formateado }}</span><br>
                <span class="suave">Folio fiscal (UUID)</span><br>
                <span>{{ $factura->uuid_fiscal }}</span>
            </td>
        </tr>
    </table>

    <table class="partes">
        <tr>
            <td>
                <div class="titulo">Receptor</div>
                <strong>{{ $receptor['razon_social'] }}</strong><br>
                RFC {{ $receptor['rfc'] }}<br>
                Régimen {{ $regimen($receptor['regimen_fiscal']) }}<br>
                Domicilio fiscal (CP) {{ $receptor['codigo_postal'] }}<br>
                Uso de CFDI {{ $factura->uso_cfdi->value }} – {{ $factura->uso_cfdi->descripcion() }}
            </td>
            <td>
                <div class="titulo">Comprobante</div>
                Tipo: I – Ingreso · CFDI {{ $factura->version_comprobante ?? '4.0' }}<br>
                Fecha de timbrado: {{ $factura->fecha_timbrado?->setTimezone($zona)->format('d/m/Y H:i:s') }}<br>
                Lugar de expedición (CP): {{ $factura->lugar_expedicion ?? '—' }}<br>
                Método de pago: {{ $factura->metodo_pago->value }} – {{ $factura->metodo_pago->etiqueta() }}<br>
                Forma de pago: {{ $factura->forma_pago->value }} – {{ $factura->forma_pago->descripcion() }}<br>
                Moneda: {{ App\Models\Factura::MONEDA }}
            </td>
        </tr>
    </table>

    <table class="lineas">
        <thead>
            <tr>
                <th class="numero">Cant.</th>
                <th>Unidad</th>
                <th>Clave</th>
                <th>Descripción</th>
                <th class="numero">Precio unitario</th>
                <th class="numero">Descuento</th>
                <th>IVA</th>
                <th class="numero">Importe</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($factura->lineas as $linea)
                <tr>
                    <td class="numero">{{ $linea->cantidad }}</td>
                    <td>{{ $linea->clave_unidad }}</td>
                    <td>{{ $linea->clave_prod_serv }}</td>
                    <td>{{ $linea->descripcion }}<br><span class="suave">Modelo {{ $linea->modelo }} · Objeto imp. {{ $linea->objeto_imp->value }}</span></td>
                    <td class="numero">{{ $pesos($linea->precio_unitario) }}</td>
                    <td class="numero">{{ (float) $linea->descuentoCfdi() > 0 ? $pesos($linea->descuentoCfdi()) : '—' }}</td>
                    <td>{{ $linea->tasa_iva->etiqueta() }}</td>
                    <td class="numero">{{ $pesos($linea->importe) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totales">
        <tr><td>Subtotal</td><td class="numero">{{ $pesos($factura->subtotal) }}</td></tr>
        @if ((float) $factura->total_descuento > 0)
            <tr><td>Descuento</td><td class="numero">−{{ $pesos($factura->total_descuento) }}</td></tr>
        @endif
        <tr><td>IVA trasladado 16%</td><td class="numero">{{ $pesos($factura->total_iva_16) }}</td></tr>
        <tr class="total"><td>Total</td><td class="numero">{{ $pesos($factura->total) }}</td></tr>
    </table>
    <p class="letra">{{ $importeEnLetra }}</p>

    <table class="sellos">
        <tr>
            <td style="width: 110pt;">
                @if ($qr)
                    <img src="{{ $qr }}" alt="Código QR de verificación del SAT" style="width: 100pt; height: 100pt;">
                @endif
            </td>
            <td>
                <div class="titulo">Sello digital del CFDI</div>
                <p class="sello">{!! $renglones($factura->sello_cfdi) !!}</p>
                <div class="titulo">Sello del SAT</div>
                <p class="sello">{!! $renglones($factura->sello_sat) !!}</p>
                <div class="titulo">Cadena original del complemento de certificación digital del SAT</div>
                <p class="sello">{!! $renglones($factura->cadena_original_sat) !!}</p>
                <div class="titulo">No. de certificado del SAT</div>
                <p class="sello">{{ $factura->no_certificado_sat }}</p>
            </td>
        </tr>
    </table>

    <p class="leyenda">Este documento es una representación impresa de un CFDI.</p>
</body>
</html>
