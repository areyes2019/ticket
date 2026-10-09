{{-- Barra de secciones del mostrador (034): fija al pie, tres secciones del
     mismo ancho y la actual marcada. No hay "Inicio": el nombre del sistema,
     arriba, regresa a los tres accesos. --}}
@php
    $secciones = [
        'mostrador.cotizaciones' => ['Cotizaciones', 'file-earmark-text'],
        'mostrador.facturas' => ['Facturas', 'receipt'],
        'mostrador.catalogo' => ['Catálogo', 'box-seam'],
    ];
@endphp

<nav class="mostrador-secciones" aria-label="Secciones del mostrador">
    @foreach ($secciones as $ruta => [$texto, $icono])
        <a href="{{ route($ruta) }}" class="mostrador-seccion" @if (request()->routeIs($ruta, $ruta.'.*')) aria-current="page" @endif>
            <x-icono :nombre="$icono" />
            <span>{{ $texto }}</span>
        </a>
    @endforeach
</nav>
