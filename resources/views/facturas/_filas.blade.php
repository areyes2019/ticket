<div id="facturas-filas" class="bandeja-filas">
    @if ($facturas->isEmpty())
        <p class="bandeja-vacia"><x-icono nombre="receipt" />Sin facturas</p>
    @else
        <ul class="bandeja-filas-lista">
            @foreach ($facturas as $factura)
                <x-facturas.fila :factura="$factura" :activa="isset($abierta) && $abierta?->is($factura)" />
            @endforeach
        </ul>
    @endif
</div>
