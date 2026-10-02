@php
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
    $zona = config('app.zona_negocio');
    $proveedor = $orden->proveedor;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Orden de compra {{ $orden->folio_formateado }}</title>
    {{-- Dompdf no lee public/css/app.css: los valores copian los tokens de diseño (003). --}}
    <style>
        @page { margin: 1.5cm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10pt; color: #1f2937; }
        h1 { margin: 0; font-size: 16pt; color: #1e3a5f; }
        .encabezado { width: 100%; border-bottom: 2px solid #1e3a5f; margin-bottom: 12pt; }
        .encabezado td { vertical-align: bottom; padding-bottom: 6pt; }
        .derecha { text-align: right; }
        .suave { color: #6b7280; }
        .proveedor { margin-bottom: 12pt; }
        .proveedor td { padding: 1pt 8pt 1pt 0; }
        table.lineas { width: 100%; border-collapse: collapse; }
        table.lineas th { background: #f3f4f6; text-align: left; font-size: 9pt; }
        table.lineas th, table.lineas td { padding: 4pt; border-bottom: 1px solid #e5e7eb; }
        .numero, table.lineas th.numero { text-align: right; white-space: nowrap; }
        table.totales { margin-top: 10pt; margin-left: auto; border-collapse: collapse; }
        table.totales td { padding: 2pt 0 2pt 16pt; }
        .total td { font-weight: bold; font-size: 12pt; border-top: 1px solid #1f2937; }
        .observaciones { margin-top: 16pt; }
    </style>
</head>
<body>
    <table class="encabezado">
        <tr>
            <td><h1>{{ config('app.name') }}</h1></td>
            <td class="derecha">
                <strong>Orden de compra {{ $orden->folio_formateado }}</strong><br>
                <span class="suave">{{ $orden->created_at->setTimezone($zona)->format('d/m/Y') }}</span>
            </td>
        </tr>
    </table>

    <table class="proveedor">
        <tr><td class="suave">Proveedor</td><td><strong>{{ $proveedor->nombre_comercial }}</strong></td></tr>
        @if ($proveedor->rfc)
            <tr><td class="suave">RFC</td><td>{{ $proveedor->rfc }}</td></tr>
        @endif
        @if ($proveedor->nombre_contacto)
            <tr><td class="suave">Contacto</td><td>{{ $proveedor->nombre_contacto }}</td></tr>
        @endif
        @if ($proveedor->correo)
            <tr><td class="suave">Correo</td><td>{{ $proveedor->correo }}</td></tr>
        @endif
        @if ($proveedor->telefono)
            <tr><td class="suave">Teléfono</td><td>{{ $proveedor->telefono }}</td></tr>
        @endif
        @if ($orden->fecha_entrega_esperada)
            <tr><td class="suave">Entrega esperada</td><td><strong>{{ $orden->fecha_entrega_esperada->format('d/m/Y') }}</strong></td></tr>
        @endif
    </table>

    <table class="lineas">
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

    <table class="totales">
        <tr><td>Subtotal</td><td class="numero">{{ $pesos($orden->subtotal) }}</td></tr>
        @if ((float) $orden->total_descuento > 0)
            <tr><td>Descuento</td><td class="numero">−{{ $pesos($orden->total_descuento) }}</td></tr>
        @endif
        <tr><td>IVA 16% (acreditable)</td><td class="numero">{{ $pesos($orden->total_iva_16) }}</td></tr>
        <tr class="total"><td>Total</td><td class="numero">{{ $pesos($orden->total) }}</td></tr>
    </table>

    @if ($orden->observaciones)
        <div class="observaciones">
            <strong>Observaciones</strong><br>
            {!! nl2br(e($orden->observaciones)) !!}
        </div>
    @endif
</body>
</html>
