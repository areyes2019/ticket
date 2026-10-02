<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Orden de compra {{ $orden->folio_formateado }}</title>
</head>
<body style="font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #1f2937;">
    <p>Hola, {{ $orden->proveedor->nombre_contacto ?: $orden->proveedor->nombre_comercial }}:</p>

    <p>Te enviamos la orden de compra <strong>{{ $orden->folio_formateado }}</strong> por un total de
        <strong>${{ number_format((float) $orden->total, 2) }}</strong> (IVA incluido). El detalle va en el PDF adjunto.</p>

    @if ($orden->fecha_entrega_esperada)
        <p>Fecha de entrega esperada: <strong>{{ $orden->fecha_entrega_esperada->format('d/m/Y') }}</strong>.</p>
    @endif

    <p>Quedamos atentos a tu confirmación.</p>

    <p>{{ config('app.name') }}</p>
</body>
</html>
