@props(['cotizacion', 'activa' => false])

{{-- Una cotización en la lista de la bandeja. Sin JavaScript el enlace abre el
     detalle; con él, bandeja-cotizaciones.js la muestra en el visor. --}}
@php
    $fecha = $cotizacion->created_at->setTimezone(config('app.zona_negocio'));
    $hoy = now(config('app.zona_negocio'));
    $fechaCorta = match (true) {
        $fecha->isSameDay($hoy) => $fecha->format('H:i'),
        $fecha->isSameDay($hoy->subDay()) => 'Ayer',
        default => rtrim($fecha->translatedFormat('j M'), '.'),
    };
@endphp

<li @class(['bandeja-fila', 'bandeja-fila-activa' => $activa]) data-cotizacion="{{ $cotizacion->id }}">
    <a href="{{ route('cotizaciones.show', $cotizacion) }}" class="bandeja-abrir"
       data-vista-previa="{{ route('cotizaciones.vista-previa', $cotizacion) }}" @if ($activa) aria-current="true" @endif>
        <x-bandeja.avatar :nombre="$cotizacion->cliente->razon_social" />

        <span class="bandeja-fila-texto">
            <span class="bandeja-fila-linea">
                <span class="bandeja-fila-remitente">{{ $cotizacion->cliente->razon_social }}</span>
                <time class="bandeja-fila-fecha" datetime="{{ $fecha->toIso8601String() }}">{{ $fechaCorta }}</time>
            </span>
            <span class="bandeja-fila-linea">
                <span class="bandeja-fila-asunto">{{ $cotizacion->folio_formateado }}</span>
                <span class="bandeja-fila-total">${{ number_format((float) $cotizacion->total, 2) }}</span>
            </span>
            <span class="bandeja-fila-marcas">
                <span @class(['etiqueta', $cotizacion->estado->claseEtiqueta()])>{{ $cotizacion->estado->etiqueta() }}</span>
                @if ($cotizacion->estaFacturada())
                    <span class="etiqueta etiqueta-facturada">Facturada</span>
                @endif
                @if ($cotizacion->mostrarAvisoCaducidad())
                    <span class="etiqueta etiqueta-suspendido" data-aviso-caducidad>{{ $cotizacion->textoCaducidad() }}</span>
                @endif
            </span>
        </span>
    </a>
</li>
