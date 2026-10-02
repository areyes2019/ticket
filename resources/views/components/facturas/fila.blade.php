@props(['factura', 'activa' => false])

{{-- Una factura en la lista del dashboard. Sin JavaScript el enlace abre el
     detalle; con él, bandeja-documentos.js la muestra en el visor. --}}
@php
    $fecha = $factura->created_at->setTimezone(config('app.zona_negocio'));
    $hoy = now(config('app.zona_negocio'));
    $fechaCorta = match (true) {
        $fecha->isSameDay($hoy) => $fecha->format('H:i'),
        $fecha->isSameDay($hoy->subDay()) => 'Ayer',
        default => rtrim($fecha->translatedFormat('j M'), '.'),
    };
@endphp

<li @class(['bandeja-fila', 'bandeja-fila-activa' => $activa]) data-factura="{{ $factura->id }}">
    <a href="{{ route('facturas.show', $factura) }}" class="bandeja-abrir"
       data-vista-previa="{{ route('facturas.vista-previa', $factura) }}" @if ($activa) aria-current="true" @endif>
        <x-bandeja.avatar :nombre="$factura->cliente->razon_social" />

        <span class="bandeja-fila-texto">
            <span class="bandeja-fila-linea">
                <span class="bandeja-fila-remitente">{{ $factura->cliente->razon_social }}</span>
                <time class="bandeja-fila-fecha" datetime="{{ $fecha->toIso8601String() }}">{{ $fechaCorta }}</time>
            </span>
            <span class="bandeja-fila-linea">
                <span class="bandeja-fila-asunto">{{ $factura->folioVisible() }}</span>
                <span class="bandeja-fila-total">${{ number_format((float) $factura->total, 2) }}</span>
            </span>
            <span class="bandeja-fila-marcas">
                <span @class(['etiqueta', $factura->estado->claseEtiqueta()])>{{ $factura->estado->etiqueta() }}</span>
                @if ($factura->cancelacionEnCurso())
                    <span class="etiqueta etiqueta-suspendido">Cancelación en proceso</span>
                @endif
            </span>
        </span>
    </a>
</li>
