{{-- Atajos de fecha. Se vuelven a pintar con cada búsqueda para llevar los filtros actuales y marcar el activo. --}}
<nav id="cotizaciones-atajos" class="atajos" aria-label="Periodo">
    @foreach (App\Http\Requests\ListadoCotizacionesRequest::PERIODOS as $clave => $texto)
        @php
            $enlace = array_filter([
                ...$campos,
                'periodo' => $clave === App\Http\Requests\ListadoCotizacionesRequest::PERIODO_DEFECTO ? '' : $clave,
            ]);
        @endphp
        <a href="{{ route('cotizaciones.index', $enlace) }}" @class(['atajo', 'atajo-activo' => $periodo === $clave]) @if ($periodo === $clave) aria-current="true" @endif data-busqueda-enlace>{{ $texto }}</a>
    @endforeach
</nav>
