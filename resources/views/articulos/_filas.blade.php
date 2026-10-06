<tbody id="articulos-filas" aria-live="polite">
    @forelse ($articulos as $articulo)
        <tr>
            <td>
                {{-- Abre la ficha (ficha-articulo.js); sin JavaScript lleva a la edición. Nunca lleva costo ni utilidad. --}}
                <a href="{{ route('articulos.edit', $articulo) }}" class="enlace-ficha" data-ficha
                    data-nombre="{{ $articulo->nombre }}" data-modelo="{{ $articulo->modelo }}" data-precio="${{ number_format($articulo->precio_unitario_con_iva, 2) }}" data-precio-distribuidor="${{ number_format($articulo->precio_distribuidor_con_iva, 2) }}" data-tipo="{{ $articulo->requiereProduccion() ? 'Producción' : 'Suministro' }} (por su catálogo)"
                    data-etiqueta-precio="{{ $articulo->objeto_imp === App\Enums\ObjetoImpuesto::SiObjeto ? 'Precio con IVA' : 'Precio' }}"
                    data-imagen="{{ $articulo->tiene_imagen ? route('articulos.imagen', [$articulo, 'v' => $articulo->imagen_version]) : '' }}">{{ $articulo->nombre }}</a>
            </td>
            <td><span class="celda-truncada" title="{{ $articulo->modelo }}">{{ $articulo->modelo }}</span></td>
            <td class="numero">${{ number_format((float) $articulo->costo_con_descuento, 2) }}</td>
            <td class="numero">${{ number_format($articulo->precio_unitario_con_iva, 2) }}</td>
            <td class="numero">${{ number_format($articulo->precio_distribuidor_con_iva, 2) }}</td>
            <td>
                {{-- Las dos llevan a la ficha de existencias; con "No", la ficha abre el alta. --}}
                @if ($articulo->existencia_exists)
                    <a href="{{ route('existencias.show', $articulo) }}" class="etiqueta etiqueta-activa" title="Ver en existencias">Sí</a>
                @else
                    <a href="{{ route('existencias.show', $articulo) }}" title="Pasar a existencias">No</a>
                @endif
            </td>
            <td>
                <div class="acciones acciones-chicas">
                    <x-boton :href="route('articulos.edit', $articulo)" variante="suave" icono="pencil" title="Editar" descripcion="Editar {{ $articulo->nombre }}" />

                    <form method="POST" action="{{ route('articulos.destroy', $articulo) }}">
                        @csrf
                        @method('DELETE')
                        <x-boton variante="peligro" icono="trash" title="Eliminar" descripcion="Eliminar {{ $articulo->nombre }}" data-confirmar="¿Eliminar este artículo?" />
                    </form>
                </div>
            </td>
        </tr>
    @empty
        <tr>
            <td colspan="7">
                {{ array_filter($filtros) !== [] ? 'Ningún artículo coincide con la búsqueda.' : 'Todavía no tienes artículos registrados.' }}
            </td>
        </tr>
    @endforelse
</tbody>
