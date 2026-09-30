@props(['correo', 'etiquetas' => [], 'visible' => false])

@php
    $etiqueta = collect($etiquetas)->firstWhere('clave', $correo['etiqueta']);
@endphp

<article {{ $attributes->class('bandeja-correo') }} id="bandeja-correo-{{ $correo['id'] }}" data-visor="{{ $correo['id'] }}"
         aria-labelledby="bandeja-asunto-{{ $correo['id'] }}" @unless ($visible) hidden @endunless>
    <div class="bandeja-acciones">
        <x-boton variante="suave" tipo="button" icono="arrow-left" class="bandeja-volver" data-volver>Volver</x-boton>
        <x-boton variante="suave" tipo="button" icono="reply" descripcion="Responder" data-demo />
        <x-boton variante="suave" tipo="button" icono="forward" descripcion="Reenviar" data-demo />
        <x-boton variante="suave" tipo="button" icono="envelope" descripcion="Marcar como no leído" data-marcar-no-leido />
        <x-boton variante="suave" tipo="button" icono="trash" descripcion="Eliminar" data-demo />
    </div>

    <header class="bandeja-correo-encabezado">
        <h2 id="bandeja-asunto-{{ $correo['id'] }}" class="bandeja-correo-asunto">{{ $correo['asunto'] }}</h2>
        @if ($etiqueta)
            <span class="bandeja-chip bandeja-etiqueta-{{ $etiqueta['clave'] }}">{{ $etiqueta['nombre'] }}</span>
        @endif
    </header>

    <div class="bandeja-correo-remitente">
        <x-bandeja.avatar :nombre="$correo['remitente']['nombre']" />
        <div class="bandeja-correo-datos">
            <p><strong>{{ $correo['remitente']['nombre'] }}</strong> <span class="bandeja-suave">&lt;{{ $correo['remitente']['correo'] }}&gt;</span></p>
            <p class="bandeja-suave">Para: {{ $correo['destinatario'] }}</p>
            <p class="bandeja-suave"><time datetime="{{ $correo['fecha']->toIso8601String() }}">{{ $correo['fecha']->translatedFormat('j \d\e F \d\e Y, H:i') }}</time></p>
        </div>
    </div>

    <div class="bandeja-correo-cuerpo">
        @foreach ($correo['cuerpo'] as $parrafo)
            <p>{{ $parrafo }}</p>
        @endforeach
    </div>

    @if ($correo['adjunto'])
        <div class="bandeja-adjunto">
            <x-icono nombre="file-earmark-pdf" />
            <span class="bandeja-adjunto-nombre">{{ $correo['adjunto']['nombre'] }}</span>
            <span class="bandeja-suave">{{ $correo['adjunto']['tamano'] }}</span>
        </div>
    @endif
</article>
