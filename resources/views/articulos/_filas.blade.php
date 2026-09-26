<tbody id="articulos-filas" aria-live="polite">
    @forelse ($articulos as $articulo)
        <tr>
            <td><span class="celda-truncada" title="{{ $articulo->nombre }}">{{ $articulo->nombre }}</span></td>
            <td><span class="celda-truncada" title="{{ $articulo->modelo }}">{{ $articulo->modelo }}</span></td>
            <td><span class="celda-truncada" title="{{ $articulo->proveedor->nombre_comercial }}">{{ $articulo->proveedor->nombre_comercial }}</span></td>
            <td class="numero">${{ number_format($articulo->precio_unitario_con_iva, 2) }}</td>
            <td>
                <div class="acciones">
                    <x-boton :href="route('articulos.edit', $articulo)" variante="suave" icono="pencil" title="Editar" descripcion="Editar {{ $articulo->nombre }}" />

                    <form method="POST" action="{{ route('articulos.destroy', $articulo) }}">
                        @csrf
                        @method('DELETE')
                        <x-boton variante="secundario" icono="trash" title="Eliminar" descripcion="Eliminar {{ $articulo->nombre }}" data-confirmar="¿Eliminar este artículo?" />
                    </form>
                </div>
            </td>
        </tr>
    @empty
        <tr>
            <td colspan="5">
                {{ array_filter($filtros) !== [] ? 'Ningún artículo coincide con la búsqueda.' : 'Todavía no tienes artículos registrados.' }}
            </td>
        </tr>
    @endforelse
</tbody>
