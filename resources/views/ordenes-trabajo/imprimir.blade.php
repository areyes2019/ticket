<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titulo }}</title>
    {{-- Orden de trabajo y hoja de producción (022), tamaño carta y en blanco y
         negro. Estilos propios: no usa el layout de la aplicación. Sin QR,
         precios ni saldo. --}}
    <style>
        @page { size: letter; margin: 12mm; }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            background: #fff;
            color: #000;
            font-family: "DejaVu Sans", Arial, sans-serif;
            font-size: 10pt;
        }

        h1 { margin: 0 0 2mm; font-size: 14pt; }

        .impreso { margin: 0 0 5mm; color: #444; font-size: 8pt; }

        .orden {
            display: flex;
            gap: 5mm;
            padding: 4mm 0;
            border-top: 1px solid #000;
            break-inside: avoid;
        }

        .orden-datos { flex: 1; min-width: 0; }

        .orden-folio { margin: 0; font-size: 16pt; font-weight: bold; }

        .orden-cliente { margin: 1mm 0 3mm; }

        table { width: 100%; border-collapse: collapse; }

        th, td { padding: 1mm 2mm; text-align: left; border-bottom: 1px solid #999; vertical-align: top; }

        th { font-size: 8pt; text-transform: uppercase; }

        .numero { text-align: right; }

        .color { font-weight: bold; }

        .miniatura {
            flex: 0 0 30mm;
            width: 30mm;
            height: 30mm;
            object-fit: contain;
            border: 1px solid #999;
        }

        .miniatura-vacia {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            font-size: 8pt;
        }

        .diseno {
            display: block;
            max-width: 100%;
            max-height: 120mm;
            margin-top: 5mm;
            object-fit: contain;
        }

        @media screen {
            body { max-width: 216mm; margin: 0 auto; padding: 12mm; }
        }
    </style>
</head>
<body>
    @if ($esHojaProduccion)
        <h1>Hoja de producción</h1>
        <p class="impreso">Impresa el {{ now(config('app.zona_negocio'))->format('d/m/Y H:i') }} · {{ $ordenes->count() }} {{ $ordenes->count() === 1 ? 'orden' : 'órdenes' }} en proceso</p>
    @endif

    @forelse ($ordenes as $orden)
        @php($pedido = $orden->pedido)
        <section class="orden">
            @if ($esHojaProduccion)
                @if ($orden->tiene_imagen)
                    <img class="miniatura" src="{{ route('pedidos.orden-trabajo.imagen', [$pedido, 'v' => $orden->imagen_version]) }}" alt="Diseño de {{ $pedido->folio_formateado }}">
                @else
                    <div class="miniatura miniatura-vacia">Sin imagen</div>
                @endif
            @endif

            <div class="orden-datos">
                <p class="orden-folio">{{ $esHojaProduccion ? '' : 'Orden de trabajo · ' }}{{ $pedido->folio_formateado }}</p>
                <p class="orden-cliente">{{ $pedido->cliente_nombre }} · {{ $pedido->telefono_legible }}</p>

                <table>
                    <thead>
                        <tr>
                            <th>Modelo</th>
                            <th>Artículo</th>
                            <th class="numero">Cant.</th>
                            <th>Tinta</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pedido->lineasDeTrabajo() as $linea)
                            <tr>
                                <td>{{ filled($linea->modelo) ? $linea->modelo : '—' }}</td>
                                <td>{{ $linea->descripcion }}</td>
                                <td class="numero">{{ $linea->cantidad }}</td>
                                <td class="color">{{ $orden->colorDe($linea)?->colorTexto() ?? 'Sin color' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if (! $esHojaProduccion && $orden->tiene_imagen)
                    <img class="diseno" src="{{ route('pedidos.orden-trabajo.imagen', [$pedido, 'v' => $orden->imagen_version]) }}" alt="Diseño de {{ $pedido->folio_formateado }}">
                @endif
            </div>
        </section>
    @empty
        <p>No hay órdenes en proceso.</p>
    @endforelse

    @if ($ordenes->isNotEmpty())
        <script src="{{ asset('js/imprimir-al-cargar.js') }}?v={{ filemtime(public_path('js/imprimir-al-cargar.js')) }}"></script>
    @endif
</body>
</html>
