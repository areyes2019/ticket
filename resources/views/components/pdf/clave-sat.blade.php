{{-- "612 – Personas Físicas con Actividades…". Una clave que no está en el enum
     se imprime sola: un código raro es preferible a un PDF que no sale. --}}
@props(['clave', 'enum'])

@php
    $valor = $clave instanceof BackedEnum ? $clave->value : (string) $clave;
    $descripcion = $enum::tryFrom($valor)?->descripcion();
@endphp

{{ $descripcion ? $valor.' – '.$descripcion : $valor }}
