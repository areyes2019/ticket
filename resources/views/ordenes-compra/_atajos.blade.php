{{-- Atajos de fecha. Se vuelven a pintar con cada búsqueda para llevar los filtros actuales y marcar el activo. --}}
<nav id="ordenes-atajos" class="atajos" aria-label="Periodo">
    @foreach (App\Http\Requests\ListadoOrdenesCompraRequest::PERIODOS as $clave => $texto)
        @php
            $enlace = array_filter([
                ...$campos,
                'periodo' => $clave === App\Http\Requests\ListadoOrdenesCompraRequest::PERIODO_DEFECTO ? '' : $clave,
            ]);
        @endphp
        <a href="{{ route('ordenes-compra.index', $enlace) }}" @class(['atajo', 'atajo-activo' => $periodo === $clave]) @if ($periodo === $clave) aria-current="true" @endif data-busqueda-enlace>{{ $texto }}</a>
    @endforeach
</nav>
