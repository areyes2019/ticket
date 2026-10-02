@props(['orden', 'activa' => false])

{{-- Una orden en la lista de la bandeja. Sin JavaScript el enlace abre el
     detalle; con él, bandeja-documentos.js la muestra en el visor. --}}
@php
    $fecha = $orden->created_at->setTimezone(config('app.zona_negocio'));
    $hoy = now(config('app.zona_negocio'));
    $fechaCorta = match (true) {
        $fecha->isSameDay($hoy) => $fecha->format('H:i'),
        $fecha->isSameDay($hoy->subDay()) => 'Ayer',
        default => rtrim($fecha->translatedFormat('j M'), '.'),
    };
@endphp

<li @class(['bandeja-fila', 'bandeja-fila-activa' => $activa]) data-orden="{{ $orden->id }}">
    <a href="{{ route('ordenes-compra.show', $orden) }}" class="bandeja-abrir"
       data-vista-previa="{{ route('ordenes-compra.vista-previa', $orden) }}" @if ($activa) aria-current="true" @endif>
        <x-bandeja.avatar :nombre="$orden->proveedor->nombre_comercial" />

        <span class="bandeja-fila-texto">
            <span class="bandeja-fila-linea">
                <span class="bandeja-fila-remitente">{{ $orden->proveedor->nombre_comercial }}</span>
                <time class="bandeja-fila-fecha" datetime="{{ $fecha->toIso8601String() }}">{{ $fechaCorta }}</time>
            </span>
            <span class="bandeja-fila-linea">
                <span class="bandeja-fila-asunto">{{ $orden->folio_formateado }}</span>
                <span class="bandeja-fila-total">${{ number_format((float) $orden->total, 2) }}</span>
            </span>
            <span class="bandeja-fila-marcas">
                <span @class(['etiqueta', $orden->estado->claseEtiqueta()])>{{ $orden->estado->etiqueta() }}</span>
                @if ($orden->fecha_entrega_esperada && $orden->estado !== App\Enums\EstadoOrdenCompra::Recibida)
                    <span class="etiqueta etiqueta-borrador" title="Fecha de entrega esperada">Entrega {{ $orden->fecha_entrega_esperada->format('d/m') }}</span>
                @endif
            </span>
        </span>
    </a>
</li>
