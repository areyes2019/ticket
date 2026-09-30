<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Factura {{ $factura->folioVisible() }}</title>
</head>
<body style="font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #1f2937;">
    <p>Hola, {{ $factura->cliente->nombre_contacto ?: $receptor['razon_social'] }}:</p>

    <p>Te enviamos la factura <strong>{{ $factura->folioVisible() }}</strong> por un total de
        <strong>${{ number_format((float) $factura->total, 2) }}</strong> (IVA incluido).</p>

    <p>Folio fiscal (UUID): {{ $factura->uuid_fiscal }}</p>

    <p>Adjuntamos el XML y su representación impresa en PDF.</p>

    <p>{{ config('app.name') }}</p>
</body>
</html>
