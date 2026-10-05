{{-- Cuerpo de la orden: la venta, los artículos con su color y la imagen del
     diseño. Lo comparten el detalle (show) y el visor del dashboard
     (_vista-previa). --}}
<x-card titulo="Venta">
    <dl class="hoja-cliente">
        <div><dt>Venta</dt><dd><a href="{{ route('pedidos.show', $pedido) }}">{{ $pedido->folio_formateado }}</a></dd></div>
        <div><dt>Cliente</dt><dd>{{ $pedido->cliente_nombre }}</dd></div>
        <div><dt>Teléfono</dt><dd>{{ $pedido->telefono_legible }}</dd></div>
    </dl>
</x-card>

<x-card titulo="Artículos y color de tinta" class="tabla-contenedor">
    <table class="tabla">
        <thead>
            <tr>
                <th>Modelo</th>
                <th>Artículo</th>
                <th class="numero">Cant.</th>
                <th>Color de tinta</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($pedido->lineasDeTrabajo() as $linea)
                @php($renglon = $orden->colorDe($linea))
                <tr>
                    <td>{{ filled($linea->modelo) ? $linea->modelo : '—' }}</td>
                    <td>{{ $linea->descripcion }}</td>
                    <td class="numero">{{ $linea->cantidad }}</td>
                    <td>
                        @if ($renglon)
                            <strong>{{ $renglon->colorTexto() }}</strong>
                        @else
                            <span class="etiqueta etiqueta-sin-orden">Sin color</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</x-card>

<x-card titulo="Imagen del diseño">
    @if ($orden->tiene_imagen)
        <img class="imagen-diseno" src="{{ route('pedidos.orden-trabajo.imagen', [$pedido, 'v' => $orden->imagen_version]) }}" alt="Diseño de la venta {{ $pedido->folio_formateado }}">
    @else
        <p class="ayuda">Sin imagen del diseño.</p>
    @endif
</x-card>
