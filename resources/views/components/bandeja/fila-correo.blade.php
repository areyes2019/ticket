@props(['correo', 'etiquetas' => [], 'visible' => true, 'activa' => false])

@php
    $fecha = $correo['fecha'];
    $fechaCorta = match (true) {
        $fecha->isToday() => $fecha->format('H:i'),
        $fecha->isYesterday() => 'Ayer',
        default => rtrim($fecha->translatedFormat('j M'), '.'),
    };
    $etiqueta = collect($etiquetas)->firstWhere('clave', $correo['etiqueta']);
    $fragmento = implode(' ', $correo['cuerpo']);
@endphp

<li @class(['bandeja-fila', 'bandeja-fila-no-leida' => ! $correo['leido'], 'bandeja-fila-activa' => $activa])
    data-correo="{{ $correo['id'] }}"
    data-carpeta="{{ $correo['carpeta'] }}"
    data-etiqueta="{{ $correo['etiqueta'] }}"
    data-leido="{{ $correo['leido'] ? '1' : '0' }}"
    data-destacado="{{ $correo['destacado'] ? '1' : '0' }}"
    data-importante="{{ $correo['importante'] ? '1' : '0' }}"
    data-texto="{{ $correo['remitente']['nombre'] }} {{ $correo['asunto'] }} {{ $fragmento }}"
    @unless ($visible) hidden @endunless>
    <button type="button" class="bandeja-estrella" data-estrella aria-pressed="{{ $correo['destacado'] ? 'true' : 'false' }}" aria-label="Destacar correo">
        <x-icono :nombre="$correo['destacado'] ? 'star-fill' : 'star'" />
    </button>

    <button type="button" class="bandeja-abrir" data-abrir aria-controls="bandeja-correo-{{ $correo['id'] }}">
        <x-bandeja.avatar :nombre="$correo['remitente']['nombre']" />

        <span class="bandeja-fila-texto">
            <span class="bandeja-fila-linea">
                <span class="bandeja-fila-remitente">{{ $correo['remitente']['nombre'] }}</span>
                <time class="bandeja-fila-fecha" datetime="{{ $fecha->toIso8601String() }}">{{ $fechaCorta }}</time>
            </span>
            <span class="bandeja-fila-asunto">{{ $correo['asunto'] }}</span>
            <span class="bandeja-fila-fragmento">{{ $fragmento }}</span>
            @if ($etiqueta || $correo['adjunto'])
                <span class="bandeja-fila-marcas">
                    @if ($etiqueta)
                        <span class="bandeja-chip bandeja-etiqueta-{{ $etiqueta['clave'] }}">{{ $etiqueta['nombre'] }}</span>
                    @endif
                    @if ($correo['adjunto'])
                        <x-icono nombre="paperclip" class="bandeja-clip" />
                    @endif
                </span>
            @endif
        </span>
    </button>
</li>
