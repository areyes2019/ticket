<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $prueba ? 'Hoja de prueba' : 'Etiquetas de producción' }}</title>
    {{-- Etiquetas de producción (030) sobre hoja carta, con las medidas de la
         planilla (031) en variables CSS. No usa el layout de la aplicación:
         app.css solo da forma a los componentes de la barra, y los estilos de
         abajo mandan en la planilla. No se imprime sola al cargar: primero se
         ajustan las medidas y se elige en qué etiqueta empezar. La hoja de
         prueba sí. --}}
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
            grid-template-columns: repeat(var(--columnas), var(--ancho));
            grid-template-rows: repeat(var(--renglones), var(--alto));
            column-gap: var(--sep-h);
            row-gap: var(--sep-v);
            align-content: start;
            justify-content: start;
            width: 215.9mm;
            height: 279.4mm;
            padding: var(--margen-sup) 0 0 var(--margen-izq);
            overflow: hidden;
            break-after: page;
        }

        .planilla-hoja:last-child { break-after: auto; }

        .planilla-etiqueta,
        .planilla-prueba {
            border: 0.2mm solid #000;
            overflow: hidden;
        }

        .planilla-etiqueta {
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 2mm;
            line-height: 1.2;
        }

        /* Un renglón que no cabe achica su letra (etiquetas-produccion.js) y, al mínimo, se corta. */
        .planilla-etiqueta p {
            margin: 0;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            font-size: max(7pt, calc(10pt * var(--escala)));
        }

        /* La letra crece y se achica con el alto de la etiqueta (031, corrección 1). */
        .planilla-etiqueta .planilla-ticket { font-size: max(7pt, calc(14pt * var(--escala))); font-weight: bold; }

        .planilla-etiqueta .planilla-saldo { font-size: max(7pt, calc(11pt * var(--escala))); font-weight: bold; }

        .planilla-prueba {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: max(7pt, calc(14pt * var(--escala)));
            font-weight: bold;
        }

        .planilla-barra { display: none; }

        @media screen {
            body { padding: 1rem; background: #eee; }

            .planilla-barra {
                display: flex;
                flex-direction: column;
                gap: 0.75rem;
                max-width: 215.9mm;
                margin: 0 auto 1rem;
                font-size: 0.9rem;
            }

            .planilla-barra h1 { margin: 0; font-size: 1.25rem; }

            .planilla-barra p { margin: 0; }

            #planilla-medidas { display: flex; flex-direction: column; gap: 0.75rem; }

            .planilla-grupo { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 0.5rem 0.75rem; }

            .planilla-grupo .campo { margin: 0; }

            .planilla-grupo input[type="number"] { width: 6.5rem; }

            .planilla-resumen { color: #444; }

            .planilla-hoja { margin: 0 auto 1rem; background: #fff; box-shadow: 0 1px 4px rgba(0, 0, 0, 0.25); }
        }
    </style>
</head>
<body>
    <div class="planilla-barra">
        <h1>{{ $prueba ? 'Hoja de prueba' : 'Etiquetas de producción' }}</h1>

        @if (session('exito'))
            <x-alerta tipo="exito">{{ session('exito') }}</x-alerta>
        @endif

        @if ($errors->any())
            <x-alerta tipo="error">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </x-alerta>
        @endif

        @if ($prueba)
            <p class="planilla-resumen">{{ $medidas->columnas() }} × {{ $medidas->renglones() }} = {{ $medidas->porHoja() }} por hoja. Imprímela en papel normal y empálmala con la planilla contra la luz.</p>
            <div class="planilla-grupo">
                <x-boton :href="route('pedidos.produccion.etiquetas', array_filter(['formato' => request('formato'), ...$medidas->toArray()]))" variante="secundario" icono="arrow-left">Volver</x-boton>
                <x-boton tipo="button" icono="printer" data-imprimir>Imprimir</x-boton>
            </div>
        @else
            {{-- Formato: cambiarlo recarga la página con sus medidas. --}}
            <div class="planilla-grupo">
                <form method="GET" action="{{ route('pedidos.produccion.etiquetas') }}" class="planilla-grupo">
                    <x-campo nombre="formato" etiqueta="Formato" tipo="select" :vacia="false" :valor="$formato?->id ?? 'fabrica'"
                        :opciones="['fabrica' => 'Medidas de fábrica'] + $formatos->mapWithKeys(fn ($f) => [$f->id => $f->nombre.($f->es_predeterminado ? ' ★' : '')])->all()"
                        data-cargar-formato />
                    <noscript><x-boton variante="secundario">Cargar</x-boton></noscript>
                </form>

                @if ($formato)
                    <form method="POST" action="{{ route('formatos-etiqueta.duplicar', $formato) }}">
                        @csrf
                        <x-boton variante="secundario" icono="copy">Duplicar</x-boton>
                    </form>
                    <form method="POST" action="{{ route('formatos-etiqueta.destroy', $formato) }}">
                        @csrf
                        @method('DELETE')
                        <x-boton variante="secundario" icono="trash" data-confirmar="¿Eliminar el formato {{ $formato->nombre }}?">Eliminar</x-boton>
                    </form>
                @endif
            </div>

            {{-- Medidas: el primer botón es "Aplicar", el que usa Enter. Con JavaScript la
                 vista previa cambia al escribir y "Centrar" no envía nada. --}}
            <form method="POST" action="{{ route('pedidos.produccion.etiquetas.aplicar') }}" id="planilla-medidas">
                @csrf
                <input type="hidden" name="formato" value="{{ $formato?->id ?? 'fabrica' }}">

                <div class="planilla-grupo">
                    @foreach (\App\Services\Etiquetas\MedidasPlanilla::CAMPOS as $campo => [$minimo, $maximo])
                        <x-campo :nombre="$campo" :etiqueta="\App\Services\Etiquetas\MedidasPlanilla::ETIQUETAS[$campo].' (mm)'" tipo="number"
                            :valor="$medidas->milimetros($campo)" step="0.1" :min="$minimo / 10" :max="$maximo / 10" data-medida />
                    @endforeach
                    <x-campo nombre="inicio" etiqueta="Empezar en la etiqueta" tipo="number" :valor="$inicio" min="1" :max="max(1, $medidas->porHoja())" />
                </div>

                <div class="planilla-grupo">
                    <x-boton variante="secundario">Aplicar</x-boton>
                    <x-boton variante="secundario" icono="bounding-box" name="centrar" value="1" data-centrar>Centrar</x-boton>
                    <x-boton variante="secundario" icono="grid-3x3" name="prueba" value="1" formtarget="_blank">Imprimir prueba</x-boton>
                </div>

                <div class="planilla-grupo">
                    <x-campo nombre="nombre" etiqueta="Nombre del formato" :valor="$formato?->nombre" maxlength="60" />
                    <x-campo nombre="es_predeterminado" etiqueta="Predeterminado" tipo="checkbox" :valor="$formato?->es_predeterminado" />
                    @if ($formato)
                        <x-boton variante="secundario" icono="floppy" :formaction="route('formatos-etiqueta.update', $formato)">Guardar</x-boton>
                    @endif
                    <x-boton variante="secundario" icono="plus-lg" :formaction="route('formatos-etiqueta.store')">Guardar como nuevo</x-boton>
                </div>
            </form>

            <div class="planilla-grupo">
                <p class="planilla-resumen">
                    <span>{{ $ordenes->isEmpty() ? 'No hay órdenes en proceso.' : $ordenes->count().' '.($ordenes->count() === 1 ? 'orden' : 'órdenes').' en proceso' }}</span>
                    · <span id="planilla-distribucion">{{ $medidas->columnas() }} × {{ $medidas->renglones() }} = {{ $medidas->porHoja() }} por hoja{{ $hojas->isEmpty() ? '' : ' · '.$hojas->count().' '.($hojas->count() === 1 ? 'hoja' : 'hojas') }}</span>
                </p>
                @if ($ordenes->isNotEmpty())
                    <x-boton tipo="button" icono="printer" data-imprimir>Imprimir</x-boton>
                @endif
            </div>
        @endif

        <x-alerta tipo="advertencia" id="planilla-aviso" :hidden="$medidas->cabe()">Con estas medidas no cabe ninguna etiqueta en la hoja carta.</x-alerta>
    </div>

    <div id="planilla" style="--ancho: {{ $medidas->milimetros('ancho') }}mm; --alto: {{ $medidas->milimetros('alto') }}mm; --sep-h: {{ $medidas->milimetros('separacion_horizontal') }}mm; --sep-v: {{ $medidas->milimetros('separacion_vertical') }}mm; --margen-sup: {{ $medidas->milimetros('margen_superior') }}mm; --margen-izq: {{ $medidas->milimetros('margen_izquierdo') }}mm; --columnas: {{ max(1, $medidas->columnas()) }}; --renglones: {{ max(1, $medidas->renglones()) }}; --escala: {{ number_format($medidas->escalaLetra(), 4, '.', '') }};">
        @foreach ($hojas as $casillas)
            <div class="planilla-hoja">
                @foreach ($casillas as $casilla)
                    @if ($prueba)
                        <div class="planilla-prueba">{{ $casilla }}</div>
                    @elseif ($casilla === null)
                        <div class="planilla-vacia"></div>
                    @else
                        @php($pedido = $casilla->pedido)
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
    </div>

    <script src="{{ asset('js/etiquetas-produccion.js') }}?v={{ filemtime(public_path('js/etiquetas-produccion.js')) }}"></script>
    @if ($prueba && $hojas->isNotEmpty())
        <script src="{{ asset('js/imprimir-al-cargar.js') }}?v={{ filemtime(public_path('js/imprimir-al-cargar.js')) }}"></script>
    @endif
</body>
</html>
