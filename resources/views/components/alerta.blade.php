@props(['tipo' => 'exito'])

@php
    $iconos = [
        'exito' => 'check-circle',
        'error' => 'x-circle',
        'advertencia' => 'exclamation-triangle',
        'info' => 'info-circle',
    ];

    if (! isset($iconos[$tipo])) {
        throw new InvalidArgumentException("Tipo de alerta desconocido: {$tipo}.");
    }
@endphp

<div {{ $attributes->class(['alerta', 'alerta-'.$tipo]) }} @if ($tipo === 'error') role="alert" @endif>
    <x-icono :nombre="$iconos[$tipo]" />
    <div class="alerta-contenido">{{ $slot }}</div>
</div>
