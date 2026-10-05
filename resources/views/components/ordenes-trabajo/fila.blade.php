@props(['orden', 'activa' => false])

{{-- Una orden de trabajo en la lista del dashboard (spec 020, corrección 1).
     Sin JavaScript el enlace abre el detalle; con él, bandeja-documentos.js la
     muestra en el visor. Espera precargados pedido.lineas y lineas. --}}
@php
    $pedido = $orden->pedido;
    $fecha = $orden->created_at->setTimezone(config('app.zona_negocio'));
    $hoy = now(config('app.zona_negocio'));
    $fechaCorta = match (true) {
        $fecha->isSameDay($hoy) => $fecha->format('H:i'),
        $fecha->isSameDay($hoy->subDay()) => 'Ayer',
        default => rtrim($fecha->translatedFormat('j M'), '.'),
    };
    $articulos = $pedido->lineasDeTrabajo()->count();
@endphp

<li @class(['bandeja-fila', 'bandeja-fila-activa' => $activa]) data-ot="{{ $orden->id }}">
    <a href="{{ route('pedidos.orden-trabajo.show', $pedido) }}" class="bandeja-abrir"
       data-vista-previa="{{ route('pedidos.orden-trabajo.vista-previa', $pedido) }}" @if ($activa) aria-current="true" @endif>
        @if ($orden->tiene_imagen)
            <img class="bandeja-miniatura" src="{{ route('pedidos.orden-trabajo.imagen', [$pedido, 'v' => $orden->imagen_version]) }}" alt="" loading="lazy">
        @else
            <span class="bandeja-miniatura bandeja-miniatura-vacia" aria-hidden="true"><x-icono nombre="image" /></span>
        @endif

        <span class="bandeja-fila-texto">
            <span class="bandeja-fila-linea">
                <span class="bandeja-fila-remitente">{{ $pedido->cliente_nombre }}</span>
                <time class="bandeja-fila-fecha" datetime="{{ $fecha->toIso8601String() }}">{{ $fechaCorta }}</time>
            </span>
            <span class="bandeja-fila-linea">
                <span class="bandeja-fila-asunto">{{ $pedido->folio_formateado }}</span>
                <span class="bandeja-fila-total">{{ $articulos }} {{ $articulos === 1 ? 'artículo' : 'artículos' }}</span>
            </span>
            <span class="bandeja-fila-marcas">
                <span @class(['etiqueta', $orden->estado->claseEtiqueta()])>{{ $orden->estado->etiqueta() }}</span>
                @if ($orden->esEditable() && $orden->lineasSinColor()->isNotEmpty())
                    <span class="etiqueta etiqueta-sin-orden">Falta color</span>
                @endif
            </span>
        </span>
    </a>
</li>
