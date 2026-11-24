<tbody id="articulos-ia-filas" aria-live="polite">
    @forelse ($articulos as $articulo)
        <tr>
            <td>{{ $articulo->modelo }}</td>
            <td><span class="celda-truncada" title="{{ $articulo->nombre }}">{{ $articulo->nombre }}</span></td>
            <td class="numero">${{ number_format($articulo->precio_unitario_con_iva, 2) }}</td>
            <td>
                {{-- Lo mismo que ve la IA (047, regla 10). --}}
                @if ($articulo->en_existencia)
                    <span class="etiqueta etiqueta-activa">En existencia</span>
                @else
                    <span class="etiqueta etiqueta-inactiva">Sobre pedido</span>
                @endif
            </td>
            <td>
                <form method="POST" action="{{ route('articulos-ia.destroy', $articulo) }}">
                    @csrf
                    @method('DELETE')
                    <x-boton variante="suave" icono="x-lg">Quitar</x-boton>
                </form>
            </td>
        </tr>
    @empty
        <tr>
            <td colspan="5">
                @if ($hayMarcados)
                    Ningún artículo de la lista coincide con la búsqueda.
                @else
                    Todavía no hay artículos. Mientras la lista esté vacía, la IA busca en todo el catálogo. <a href="{{ route('articulos-ia.agregar') }}">Agrega uno</a>.
                @endif
            </td>
        </tr>
    @endforelse
</tbody>
