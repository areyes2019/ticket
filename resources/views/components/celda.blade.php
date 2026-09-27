@props([
    'nombre',
    'etiqueta',
    'tipo' => 'text',
    'valor' => null,
    'opciones' => [],
    'vacia' => false,
    'error' => false,
])

{{-- Campo dentro de una celda de tabla: sin etiqueta visible (la lleva aria-label) y sin old(),
     porque quien lo usa ya le pasa el valor a mostrar. --}}
@php
    $attributes = $attributes->merge(array_filter([
        'aria-label' => $etiqueta,
        'aria-invalid' => $error ? 'true' : null,
    ]));
@endphp

@if ($tipo === 'select')
    <select name="{{ $nombre }}" {{ $attributes }}>
        @if ($vacia !== false)
            <option value="">{{ $vacia }}</option>
        @endif
        @foreach ($opciones as $valorOpcion => $textoOpcion)
            <option value="{{ $valorOpcion }}" @selected((string) $valor === (string) $valorOpcion)>{{ $textoOpcion }}</option>
        @endforeach
    </select>
@else
    <input type="{{ $tipo }}" name="{{ $nombre }}" value="{{ $valor }}" {{ $attributes }}>
@endif
