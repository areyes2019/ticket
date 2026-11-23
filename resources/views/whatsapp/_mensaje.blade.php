{{-- Un mensaje de la conversación (regla 57). --}}
@php
    $hora = $mensaje->enviado_en?->setTimezone(config('app.zona_negocio'))->format('d/m H:i');
    $clase = match (true) {
        $mensaje->esHerramienta() => 'wa-mensaje-herramienta',
        $mensaje->direccion === App\Models\WhatsappMensaje::INTERNO => 'wa-mensaje-sistema',
        $mensaje->esDelCliente() => 'wa-mensaje-cliente',
        default => 'wa-mensaje-negocio',
    };
@endphp

<article class="wa-mensaje {{ $clase }}" data-mensaje="{{ $mensaje->id }}">
    @if ($mensaje->esHerramienta())
        <details>
            <summary><x-icono nombre="wrench" /> {{ $mensaje->texto }} @if ($mensaje->datos['error'] ?? false)<span class="etiqueta etiqueta-fallido">error</span>@endif</summary>
            <pre class="wa-herramienta-datos">{{ $mensaje->datos['nombre'] ?? '' }}({{ json_encode($mensaje->datos['entrada'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }})
→ {{ $mensaje->datos['resultado'] ?? '' }}</pre>
        </details>
    @elseif ($mensaje->direccion === App\Models\WhatsappMensaje::INTERNO)
        <p>{{ $mensaje->texto }} <time>{{ $hora }}</time></p>
    @else
        <header class="wa-mensaje-autor">@if (in_array($mensaje->autor, ['ventas', 'facturacion'], true))<x-icono nombre="robot" />@endif{{ $mensaje->autorTexto() }}</header>

        @if ($mensaje->tieneArchivo())
            @if ($mensaje->esImagen())
                <a href="{{ route('whatsapp.archivo', $mensaje) }}" target="_blank" rel="noopener">
                    <img src="{{ route('whatsapp.archivo', $mensaje) }}" alt="{{ $mensaje->archivo_nombre }}" class="wa-imagen" loading="lazy">
                </a>
            @elseif ($mensaje->tipo === 'audio')
                <audio controls preload="none" src="{{ route('whatsapp.archivo', $mensaje) }}"></audio>
            @else
                <a href="{{ route('whatsapp.archivo', $mensaje) }}" target="_blank" rel="noopener" class="wa-archivo">
                    <x-icono nombre="file-earmark" />{{ $mensaje->archivo_nombre }}
                </a>
            @endif
        @elseif (in_array($mensaje->tipo, ['audio', 'video'], true))
            <p class="ayuda">[{{ $mensaje->tipo === 'audio' ? 'Nota de voz' : 'Video' }}: se descarga cuando el agente lo procesa]</p>
        @endif

        @if (filled($mensaje->texto))
            <p class="wa-texto">{!! nl2br(e($mensaje->texto)) !!}</p>
        @endif

        @if (isset($mensaje->datos['cotizacion_id']))
            <a href="{{ route('cotizaciones.show', $mensaje->datos['cotizacion_id']) }}" class="wa-enlace-documento"><x-icono nombre="file-earmark-text" />Ver cotización</a>
        @elseif (isset($mensaje->datos['factura_id']))
            <a href="{{ route('facturas.show', $mensaje->datos['factura_id']) }}" class="wa-enlace-documento"><x-icono nombre="receipt" />Ver factura</a>
        @endif

        <footer class="wa-mensaje-pie">
            <time>{{ $hora }}</time>
            @if ($mensaje->estadoEnvioTexto())
                <span @class(['wa-estado-envio', 'wa-estado-fallido' => $mensaje->estado_envio === 'fallido']) data-estado-envio>{{ $mensaje->estadoEnvioTexto() }}</span>
            @endif
        </footer>
    @endif
</article>
