{{-- Carpetas (periodos) y etiquetas (estados) de la bandeja. Se vuelven a pintar
     con cada búsqueda para llevar los filtros actuales y marcar los activos. --}}
@use('App\Enums\EstadoOrdenCompra')
@use('App\Http\Requests\ListadoOrdenesCompraRequest')

@php
    $enlace = fn (array $cambios) => route('ordenes-compra.index', array_filter([...$parametros, ...$cambios], fn ($valor) => $valor !== ''));
    $iconos = ['hoy' => 'calendar-day', 'semana' => 'calendar-week', 'mes' => 'calendar-month', 'todas' => 'inbox'];
@endphp

<nav class="bandeja-carpetas" id="bandeja-carpetas" aria-label="Carpetas de órdenes de compra">
    <x-boton :href="route('ordenes-compra.create')" icono="plus-lg" bloque>Nueva orden</x-boton>

    <ul class="bandeja-opciones">
        @foreach (ListadoOrdenesCompraRequest::PERIODOS as $clave => $nombre)
            <li>
                <a href="{{ $enlace(['periodo' => $clave === ListadoOrdenesCompraRequest::PERIODO_DEFECTO ? '' : $clave]) }}"
                   @class(['bandeja-opcion', 'bandeja-opcion-activa' => $periodo === $clave]) @if ($periodo === $clave) aria-current="true" @endif data-busqueda-enlace>
                    <x-icono :nombre="$iconos[$clave]" />
                    <span class="bandeja-opcion-nombre">{{ $nombre }}</span>
                    <span class="bandeja-contador" @if ($contadores[$clave] === 0) hidden @endif>{{ $contadores[$clave] }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <h2 class="bandeja-subtitulo">Etiquetas</h2>

    {{-- Carpeta y etiqueta se combinan. Pulsar la etiqueta activa la quita. --}}
    <ul class="bandeja-opciones">
        @foreach (ListadoOrdenesCompraRequest::etiquetas() as $clave => $nombre)
            <li>
                <a href="{{ $enlace(['estado' => $estado === $clave ? '' : $clave]) }}"
                   @class(['bandeja-opcion', 'bandeja-opcion-activa' => $estado === $clave]) @if ($estado === $clave) aria-current="true" @endif data-busqueda-enlace>
                    <span class="bandeja-color bandeja-color-estado {{ EstadoOrdenCompra::from($clave)->claseEtiqueta() }}" aria-hidden="true"></span>
                    <span class="bandeja-opcion-nombre">{{ $nombre }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
