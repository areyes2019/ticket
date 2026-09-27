@php
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
    $zona = config('app.zona_negocio');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cotización {{ $cotizacion->folio_formateado }}</title>
    {{-- Dompdf no lee public/css/app.css: los valores copian los tokens de diseño (003). --}}
    <style>
        @page { margin: 1.5cm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10pt; color: #1f2937; }
        h1 { margin: 0; font-size: 16pt; color: #1e3a5f; }
        .encabezado { width: 100%; border-bottom: 2px solid #1e3a5f; margin-bottom: 12pt; }
        .encabezado td { vertical-align: bottom; padding-bottom: 6pt; }
        .derecha { text-align: right; }
        .suave { color: #6b7280; }
        .cliente { margin-bottom: 12pt; }
        .cliente td { padding: 1pt 8pt 1pt 0; }
        table.lineas { width: 100%; border-collapse: collapse; }
        table.lineas th { background: #f3f4f6; text-align: left; font-size: 9pt; }
        table.lineas th, table.lineas td { padding: 4pt; border-bottom: 1px solid #e5e7eb; }
        .numero, table.lineas th.numero { text-align: right; white-space: nowrap; }
        table.totales { margin-top: 10pt; margin-left: auto; border-collapse: collapse; }
        table.totales td { padding: 2pt 0 2pt 16pt; }
        .total td { font-weight: bold; font-size: 12pt; border-top: 1px solid #1f2937; }
    </style>
</head>
<body>
    <table class="encabezado">
        <tr>
            <td><h1>{{ config('app.name') }}</h1></td>
            <td class="derecha">
                <strong>Cotización {{ $cotizacion->folio_formateado }}</strong><br>
                <span class="suave">{{ $cotizacion->created_at->setTimezone($zona)->format('d/m/Y') }}</span>
            </td>
        </tr>
    </table>

    <table class="cliente">
        <tr><td class="suave">Cliente</td><td><strong>{{ $cotizacion->cliente->razon_social }}</strong></td></tr>
        <tr><td class="suave">RFC</td><td>{{ $cotizacion->cliente->rfc }}</td></tr>
        @if ($cotizacion->cliente->correo)
            <tr><td class="suave">Correo</td><td>{{ $cotizacion->cliente->correo }}</td></tr>
        @endif
        @if ($cotizacion->cliente->telefono)
            <tr><td class="suave">Teléfono</td><td>{{ $cotizacion->cliente->telefono }}</td></tr>
        @endif
    </table>

    <table class="lineas">
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

    <table class="totales">
        <tr><td>Subtotal</td><td class="numero">{{ $pesos($cotizacion->subtotal) }}</td></tr>
        @if ((float) $cotizacion->total_descuento > 0)
            <tr><td>Descuento</td><td class="numero">−{{ $pesos($cotizacion->total_descuento) }}</td></tr>
        @endif
        <tr><td>IVA 16%</td><td class="numero">{{ $pesos($cotizacion->total_iva_16) }}</td></tr>
        <tr class="total"><td>Total</td><td class="numero">{{ $pesos($cotizacion->total) }}</td></tr>
        @if ($cotizacion->pagos->isNotEmpty())
            <tr><td>Pagado</td><td class="numero">{{ $pesos($cotizacion->totalPagado()) }}</td></tr>
            <tr><td><strong>Saldo pendiente</strong></td><td class="numero"><strong>{{ $pesos($cotizacion->saldoPendiente()) }}</strong></td></tr>
        @endif
    </table>
</body>
</html>
