<div id="cotizaciones-filas" class="bandeja-filas">
    @if ($cotizaciones->isEmpty())
        <p class="bandeja-vacia"><x-icono nombre="file-earmark-text" />Sin cotizaciones</p>
    @else
        <ul class="bandeja-filas-lista">
            @foreach ($cotizaciones as $cotizacion)
                <x-cotizaciones.fila :cotizacion="$cotizacion" :activa="isset($abierta) && $abierta?->is($cotizacion)" />
            @endforeach
        </ul>
    @endif
</div>
