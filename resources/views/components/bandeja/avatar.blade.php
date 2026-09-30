@props(['nombre'])

{{-- Iniciales del remitente con un color fijo según su nombre (sin fotos). --}}
@php
    $palabras = preg_split('/\s+/', trim($nombre), -1, PREG_SPLIT_NO_EMPTY);
    $iniciales = mb_strtoupper(collect($palabras)->take(2)->map(fn ($palabra) => mb_substr($palabra, 0, 1))->implode(''));
    $color = crc32($nombre) % 6 + 1;
@endphp

<span {{ $attributes->class(['bandeja-avatar', 'bandeja-avatar-'.$color]) }} aria-hidden="true">{{ $iniciales }}</span>
