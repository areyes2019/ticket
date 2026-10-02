<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Etiqueta {{ $pedido->folio_formateado }}</title>
    {{-- Etiqueta adhesiva de 50 × 25 mm con cuatro renglones. Sin QR: la venta
         se entrega con el botón "Entregado" (022, corrección 1). Estilos propios:
         no usa el layout de la aplicación. --}}
    <style>
        @page { size: 50mm 25mm; margin: 0; }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            background: #fff;
            color: #000;
            font-family: "DejaVu Sans", Arial, sans-serif;
        }

        .etiqueta {
            display: flex;
            align-items: center;
            gap: 1.5mm;
            width: 50mm;
            height: 25mm;
            padding: 1.5mm;
            overflow: hidden;
        }

        .etiqueta-texto {
            flex: 1;
            min-width: 0;
            font-size: 10pt;
            line-height: 1.3;
        }

        /* El nombre se recorta antes de partirse: un renglón partido empuja el saldo fuera. */
        .etiqueta-texto p {
            margin: 0;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .etiqueta-saldo { font-weight: bold; font-size: 11pt; }

        @media screen {
            body { padding: 1rem; background: #eee; }
            .etiqueta { background: #fff; outline: 1px dashed #999; }
        }
    </style>
</head>
<body>
    <div class="etiqueta">
        <div class="etiqueta-texto">
            <p>{{ $pedido->cliente_nombre }}</p>
            <p>{{ $pedido->telefono_legible }}</p>
            <p>No. {{ $pedido->numero_ticket }}</p>
            <p class="etiqueta-saldo">{{ $pedido->tieneSaldo() ? 'SALDO: $'.number_format((float) $pedido->saldoPendiente(), 2) : 'PAGADO' }}</p>
        </div>
    </div>

    <script src="{{ asset('js/etiqueta-pedido.js') }}"></script>
</body>
</html>
