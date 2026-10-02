<div id="ordenes-filas" class="bandeja-filas">
    @if ($ordenes->isEmpty())
        <p class="bandeja-vacia"><x-icono nombre="cart" />Sin órdenes de compra</p>
    @else
        <ul class="bandeja-filas-lista">
            @foreach ($ordenes as $orden)
                <x-ordenes-compra.fila :orden="$orden" :activa="isset($abierta) && $abierta?->is($orden)" />
            @endforeach
        </ul>
    @endif
</div>
