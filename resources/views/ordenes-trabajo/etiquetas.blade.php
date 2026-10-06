<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Etiquetas de producción</title>
    {{-- Etiquetas de producción (030): planilla carta de 3 × 8 etiquetas de
         60 × 30 mm, centrada y sin separación, con borde de corte. No usa el
         layout de la aplicación: app.css solo da forma a los componentes de la
         barra, y los estilos de abajo mandan en la planilla. No se imprime sola
         al cargar: primero se elige en qué etiqueta empezar. --}}
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    <style>
        @page { size: letter; margin: 0; }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            background: #fff;
            color: #000;
            font-family: "DejaVu Sans", Arial, sans-serif;
        }

        .planilla-hoja {
            display: grid;
            grid-template-columns: repeat(3, 60mm);
            grid-template-rows: repeat(8, 30mm);
            align-content: center;
            justify-content: center;
            width: 215.9mm;
            height: 279.4mm;
            overflow: hidden;
            break-after: page;
        }

        .planilla-hoja:last-of-type { break-after: auto; }

        .planilla-etiqueta {
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 2mm;
            border: 0.2mm solid #000;
            overflow: hidden;
            line-height: 1.2;
        }

        /* Un renglón que no cabe achica su letra (etiquetas-produccion.js) y, al mínimo, se corta. */
        .planilla-etiqueta p {
            margin: 0;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            font-size: 10pt;
        }

        .planilla-etiqueta .planilla-ticket { font-size: 14pt; font-weight: bold; }

        .planilla-etiqueta .planilla-saldo { font-size: 11pt; font-weight: bold; }

        .planilla-barra { display: none; }

        @media screen {
            body { padding: 1rem; background: #eee; }

            .planilla-barra {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 0.75rem 1.5rem;
                max-width: 215.9mm;
                margin: 0 auto 1rem;
                font-size: 0.9rem;
            }

            .planilla-barra h1 { margin: 0; font-size: 1.25rem; }

            .planilla-barra p { margin: 0; color: #444; }

            .planilla-inicio { display: flex; align-items: flex-end; gap: 0.5rem; }

            .planilla-inicio .campo { margin: 0; }

            .planilla-inicio input { width: 5rem; }

            .planilla-hoja { margin: 0 auto 1rem; background: #fff; box-shadow: 0 1px 4px rgba(0, 0, 0, 0.25); }
        }
    </style>
</head>
<body>
    <div class="planilla-barra">
        <h1>Etiquetas de producción</h1>

        @if ($ordenes->isEmpty())
            <p>No hay órdenes en proceso.</p>
        @else
            <p>{{ $ordenes->count() }} {{ $ordenes->count() === 1 ? 'orden' : 'órdenes' }} en proceso · {{ $hojas->count() }} {{ $hojas->count() === 1 ? 'hoja' : 'hojas' }}</p>

            <form method="GET" action="{{ route('pedidos.produccion.etiquetas') }}" class="planilla-inicio">
                <x-campo nombre="inicio" etiqueta="Empezar en la etiqueta" tipo="number" :valor="$inicio" min="1" :max="\App\Http\Controllers\EtiquetasProduccionController::POR_HOJA" />
                <x-boton variante="secundario">Aplicar</x-boton>
            </form>

            <x-boton tipo="button" icono="printer" data-imprimir>Imprimir</x-boton>
        @endif
    </div>

    @foreach ($hojas as $casillas)
        <div class="planilla-hoja">
            @foreach ($casillas as $orden)
                @if ($orden === null)
                    <div class="planilla-vacia"></div>
                @else
                    @php($pedido = $orden->pedido)
                    @php($modelos = $pedido->modelosDeTrabajo())
                    <div class="planilla-etiqueta">
                        <p class="planilla-ticket">{{ $pedido->folio_formateado }}</p>
                        <p>{{ $pedido->cliente_nombre }}</p>
                        <p>{{ $pedido->telefono_legible }}&nbsp;</p>
                        <p class="planilla-saldo">{{ $pedido->tieneSaldo() ? 'SALDO: $'.number_format((float) $pedido->saldoPendiente(), 2) : 'PAGADO' }}</p>
                        <p>{{ $modelos === [] ? '—' : implode(', ', $modelos) }}</p>
                    </div>
                @endif
            @endforeach
        </div>
    @endforeach

    @if ($ordenes->isNotEmpty())
        <script src="{{ asset('js/etiquetas-produccion.js') }}?v={{ filemtime(public_path('js/etiquetas-produccion.js')) }}"></script>
    @endif
</body>
</html>
