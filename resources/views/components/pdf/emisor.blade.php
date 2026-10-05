{{-- Bloque del emisor de los PDF (026): imprime lo que haya y omite lo vacío.
     El slot agrega renglones propios del documento (lugar de expedición, estado). --}}
@props([
    'nombre' => null,
    'rfc' => null,
    'regimen' => null,
    'domicilio' => null,
    'correo' => null,
    'telefono' => null,
])

<div class="rotulo">Emisor</div>
<div class="nombre">{{ filled($nombre) ? $nombre : config('app.name') }}</div>
@if (filled($rfc))
    RFC {{ $rfc }}<br>
@endif
@if (filled($regimen))
    Régimen <x-pdf.clave-sat :clave="$regimen" enum="App\Enums\RegimenFiscal" /><br>
@endif
@if (filled($domicilio))
    {{ $domicilio }}<br>
@endif
@if (filled($correo))
    {{ $correo }}<br>
@endif
@if (filled($telefono))
    Tel. {{ $telefono }}<br>
@endif
{{ $slot }}
