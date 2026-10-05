{{-- Caja monoespaciada para tiras sin espacios (sellos, cadena original, URL).
     Dompdf no parte palabras: lo que no cabe en el renglón no se imprime. Se corta
     el texto crudo y después se escapa cada fragmento, para no partir una entidad
     (&amp; en &am + p;).
     El CORTE va amarrado al TAMAÑO: 110 caracteres de DejaVu Sans Mono a 5.8pt
     (~3.5pt cada uno) llenan ~385pt de los ~410pt de la columna del timbre.
     Quien agrande la letra debe bajar el corte, o el texto vuelve a salirse.
     En la columna angosta del QR (23%) caben 32. --}}
@props(['texto', 'corte' => 110])

@php
    $tamano = '5.8pt';
@endphp

<div class="mono-box" style="font-size: {{ $tamano }};">{!! implode('<br>', array_map('e', str_split((string) $texto, $corte))) !!}</div>
