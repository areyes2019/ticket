@php
    $pesos = fn ($monto) => '$'.number_format((float) $monto, 2);
    $hayFiltros = $filtros['q'] !== '' || $filtros['catalogo'] !== null || $filtros['proveedor'] !== null || $filtros['por_pedir'];
@endphp

<tbody id="existencias-filas" aria-live="polite">
    @forelse ($existencias as $fila)
        <tr @class(['fila-por-pedir' => $fila->porPedir()])>
            <td>
                {{-- Sin columna de nombre: el modelo identifica al artículo y el nombre va en el title. Las acciones viven en la ficha. --}}
                <a href="{{ route('existencias.show', $fila->articulo) }}" class="celda-truncada" title="{{ $fila->articulo->nombre }}">{{ $fila->articulo->modelo }}</a>
                @if ($fila->porPedir())
                    <span class="etiqueta etiqueta-por-pedir">Por pedir</span>
                @endif
            </td>
            <td><span class="celda-truncada" title="{{ $fila->articulo->catalogo->nombre }}">{{ $fila->articulo->catalogo->nombre }}</span></td>
            <td class="numero">{{ number_format($fila->existencia) }}</td>
            <td class="numero">
                @if ($fila->faltante_pendiente > 0)
                    <span class="monto-negativo">{{ number_format($fila->faltante_pendiente) }}</span>
                @endif
            </td>
            <td class="numero">{{ number_format($fila->minimo) }}</td>
            <td class="numero">{{ $fila->maximo === null ? '—' : number_format($fila->maximo) }}</td>
            <td class="numero">{{ $pesos($fila->invertido()) }}</td>
            <td class="numero">{{ $pesos($fila->beneficio()) }}</td>
        </tr>
    @empty
        <tr>
            <td colspan="8">
                @if ($hayFiltros)
                    Ningún artículo en existencias coincide con la búsqueda.
                @else
                    Todavía no tienes artículos en existencias. <a href="{{ route('existencias.agregar') }}">Agrega uno</a> o recibe una orden de compra.
                @endif
            </td>
        </tr>
    @endforelse
</tbody>
