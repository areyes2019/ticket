{{-- Carpetas (periodos) y etiquetas (estados) de la bandeja. Se vuelven a pintar
     con cada búsqueda para llevar los filtros actuales y marcar los activos. --}}
@use('App\Enums\EstadoCotizacion')
@use('App\Http\Requests\ListadoCotizacionesRequest')
@use('App\Models\Cotizacion')

@php
    $enlace = fn (array $cambios) => route('cotizaciones.index', array_filter([...$parametros, ...$cambios], fn ($valor) => $valor !== ''));
    $iconos = ['hoy' => 'calendar-day', 'semana' => 'calendar-week', 'mes' => 'calendar-month', 'todas' => 'inbox'];
@endphp

<nav class="bandeja-carpetas" id="bandeja-carpetas" aria-label="Carpetas de cotizaciones">
    <x-boton :href="route('cotizaciones.create')" icono="plus-lg" bloque>Nueva cotización</x-boton>

    <ul class="bandeja-opciones">
        @foreach (ListadoCotizacionesRequest::PERIODOS as $clave => $nombre)
            <li>
                <a href="{{ $enlace(['periodo' => $clave === ListadoCotizacionesRequest::PERIODO_DEFECTO ? '' : $clave]) }}"
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
        @foreach (ListadoCotizacionesRequest::etiquetas() as $clave => $nombre)
            <li>
                <a href="{{ $enlace(['estado' => $estado === $clave ? '' : $clave]) }}"
                   @class(['bandeja-opcion', 'bandeja-opcion-activa' => $estado === $clave]) @if ($estado === $clave) aria-current="true" @endif data-busqueda-enlace>
                    <span class="bandeja-color bandeja-color-estado {{ match ($clave) {
                        Cotizacion::POR_CADUCAR => 'etiqueta-suspendido',
                        Cotizacion::FACTURADAS => 'etiqueta-facturada',
                        Cotizacion::POR_FACTURAR => 'etiqueta-por-facturar',
                        default => EstadoCotizacion::from($clave)->claseEtiqueta(),
                    } }}" aria-hidden="true"></span>
                    <span class="bandeja-opcion-nombre">{{ $nombre }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
