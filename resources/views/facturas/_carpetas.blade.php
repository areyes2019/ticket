{{-- Carpetas (periodos) y etiquetas (estados) de la bandeja. Se vuelven a pintar
     con cada búsqueda para llevar los filtros actuales y marcar los activos. --}}
@use('App\Enums\EstadoFactura')
@use('App\Http\Requests\ListadoFacturasRequest')

@php
    $enlace = fn (array $cambios) => route('facturas.index', array_filter([...$parametros, ...$cambios], fn ($valor) => $valor !== ''));
    $iconos = ['hoy' => 'calendar-day', 'semana' => 'calendar-week', 'mes' => 'calendar-month', 'todas' => 'inbox'];
@endphp

<nav class="bandeja-carpetas" id="bandeja-carpetas" aria-label="Carpetas de facturas">
    <x-boton :href="route('facturas.create')" icono="plus-lg" bloque>Nueva factura</x-boton>
    {{-- Sin JavaScript lleva a la página de cotizaciones por facturar; con él abre la ventana. --}}
    <x-boton :href="route('facturas.cotizaciones')" variante="secundario" icono="file-earmark-arrow-down" bloque data-abrir-elegir-cotizacion>Desde cotización</x-boton>

    <ul class="bandeja-opciones">
        @foreach (ListadoFacturasRequest::PERIODOS as $clave => $nombre)
            <li>
                <a href="{{ $enlace(['periodo' => $clave === ListadoFacturasRequest::PERIODO_DEFECTO ? '' : $clave]) }}"
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
        @foreach (EstadoFactura::cases() as $opcion)
            <li>
                <a href="{{ $enlace(['estado' => $estado === $opcion->value ? '' : $opcion->value]) }}"
                   @class(['bandeja-opcion', 'bandeja-opcion-activa' => $estado === $opcion->value]) @if ($estado === $opcion->value) aria-current="true" @endif data-busqueda-enlace>
                    <span class="bandeja-color bandeja-color-estado {{ $opcion->claseEtiqueta() }}" aria-hidden="true"></span>
                    <span class="bandeja-opcion-nombre">{{ $opcion->etiqueta() }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
