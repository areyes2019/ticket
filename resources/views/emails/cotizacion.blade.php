<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cotización {{ $cotizacion->folio_formateado }}</title>
</head>
<body style="font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #1f2937;">
    <p>Hola, {{ $cotizacion->cliente->nombre_contacto ?: $cotizacion->cliente->razon_social }}:</p>

    <p>Te enviamos la cotización <strong>{{ $cotizacion->folio_formateado }}</strong> por un total de
        <strong>${{ number_format((float) $cotizacion->total, 2) }}</strong> (IVA incluido). El detalle va en el PDF adjunto.</p>

    <p>Quedamos atentos a cualquier duda.</p>

    <p>{{ config('app.name') }}</p>
</body>
</html>
