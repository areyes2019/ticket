@props([
    'variante' => 'principal',
    'icono' => null,
    'tipo' => 'submit',
    'href' => null,
    'bloque' => false,
    'descripcion' => null,
])

@php
    $soloIcono = $slot->isEmpty();

    if ($soloIcono && ! $icono) {
        throw new InvalidArgumentException('Un botón necesita texto o icono.');
    }

    if ($soloIcono && blank($descripcion)) {
        throw new InvalidArgumentException('Un botón de solo icono necesita una descripción.');
    }

    $attributes = $attributes->class([
        'boton',
        'boton-'.$variante,
        'boton-bloque' => $bloque,
        'boton-icono' => $soloIcono,
    ]);

    if (filled($descripcion)) {
        $attributes = $attributes->merge(['aria-label' => $descripcion]);
    }
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes }}>@if ($icono)<x-icono :nombre="$icono" />@endif{{ $slot }}</a>
@else
    <button type="{{ $tipo }}" {{ $attributes }}>@if ($icono)<x-icono :nombre="$icono" />@endif{{ $slot }}</button>
@endif
